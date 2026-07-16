<?php

/**
 * 返金（キャンセル）実行。管理ダッシュボードからの POST のみを受ける。
 *
 * - 全額返金 = 実質キャンセル（金額未指定のとき）
 * - 一部返金 = amount を指定したとき（キャンセルポリシーの 50% 返金などに使用）
 *
 * 自前DBは持たないため、返金は Stripe の PaymentIntent に対して直接行う。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST のみ許可されています。');
}

csrf_verify($_POST['csrf_token'] ?? null);

$eventId = (string) ($_POST['event_id'] ?? '');
$paymentIntent = (string) ($_POST['payment_intent'] ?? '');
$amountRaw = trim((string) ($_POST['amount'] ?? ''));

/** 管理ダッシュボードへ結果を持って戻る。 */
function back_to_admin(string $eventId, string $msg, string $type): never
{
    $q = http_build_query(['event_id' => $eventId, 'msg' => $msg, 'type' => $type]);
    header('Location: index.php?' . $q, true, 303);
    exit;
}

// 対象イベントが自分のものか確認し、自分の接続アカウントに対して返金する
$event = $eventId !== '' ? find_event($eventId) : null;
if ($event === null || $event['tenant_id'] !== $tenant['id']) {
    audit_log('authz.deny', ['action' => 'refund', 'tenant' => $tenant['id'], 'event' => $eventId]);
    back_to_admin($eventId, '対象イベントが見つかりません。', 'ng');
}
$account = stripe_resolve_tenant($tenant); // 画面登録鍵→Connect→プラットフォームの順で文脈確立
if (!stripe_ready_for_tenant($tenant)) {
    back_to_admin($eventId, 'Stripe キー未設定のため返金できません。', 'ng');
}

if ($paymentIntent === '') {
    back_to_admin($eventId, '返金対象が不正です。', 'ng');
}

// IDOR対策: 指定 payment_intent が「このイベントの事前決済」であることを Stripe 側で検証する。
// （単一 Stripe 共有のため、検証しないと他テナントの決済に返金できてしまう。）
if (find_event_participant_by_payment_intent($eventId, $account, $paymentIntent) === null) {
    audit_log('authz.deny', ['action' => 'refund.pi', 'tenant' => $tenant['id'], 'event' => $eventId]);
    back_to_admin($eventId, '返金対象の決済が見つかりません。', 'ng');
}

init_stripe();
$opts = stripe_opts($account);

$refundParams = ['payment_intent' => $paymentIntent];

// 金額指定があれば一部返金。JPY は最小単位＝円なのでそのまま整数化。
if ($amountRaw !== '') {
    if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) {
        back_to_admin($eventId, '返金額の指定が不正です。', 'ng');
    }
    // 通貨に応じて最小単位へ変換（JPY はそのまま、その他は 100 倍）
    try {
        $pi = \Stripe\PaymentIntent::retrieve($paymentIntent, $opts);
        $currency = strtolower((string) ($pi->currency ?? 'jpy'));
    } catch (\Throwable $ex) {
        error_log('PI 取得失敗: ' . $ex->getMessage());
        back_to_admin($eventId, '返金対象の確認に失敗しました。', 'ng');
    }
    $refundParams['amount'] = ($currency === 'jpy')
        ? (int) round((float) $amountRaw)
        : (int) round((float) $amountRaw * 100);
}

try {
    $refund = \Stripe\Refund::create($refundParams, $opts);
    audit_log('refund', ['tenant' => $tenant['id'], 'event' => $eventId, 'pi' => substr($paymentIntent, 0, 10) . '…', 'partial' => isset($refundParams['amount']) ? '1' : '0']);
    $isPartial = isset($refundParams['amount']);
    $msg = $isPartial
        ? '一部返金を実行しました（返金ID: ' . $refund->id . '）。'
        : '全額返金（キャンセル）を実行しました（返金ID: ' . $refund->id . '）。';
    back_to_admin($eventId, $msg, 'ok');
} catch (\Throwable $ex) {
    error_log('返金失敗: ' . $ex->getMessage());
    back_to_admin($eventId, '返金に失敗しました: ' . $ex->getMessage(), 'ng');
}
