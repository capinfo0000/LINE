<?php

/**
 * 出席確認（チェックイン）の切替。当日・事前を問わず、参加者の Stripe 顧客の
 * metadata.attended を更新する（DB は持たない方針を維持）。
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
$customerId = (string) ($_POST['customer_id'] ?? '');
$attend = ($_POST['attend'] ?? '1') === '1'; // 1=出席にする / 0=取り消す

function back_to_admin(string $eventId, string $msg, string $type): never
{
    $q = http_build_query(['event_id' => $eventId, 'msg' => $msg, 'type' => $type]);
    header('Location: index.php?' . $q, true, 303);
    exit;
}

$event = $eventId !== '' ? find_event($eventId) : null;
if ($event === null || $event['tenant_id'] !== $tenant['id']) {
    back_to_admin($eventId, '対象イベントが見つかりません。', 'ng');
}
$account = stripe_resolve_tenant($tenant); // 画面登録鍵→Connect→プラットフォームの順で文脈確立
if ($customerId === '' || !stripe_ready_for_tenant($tenant)) {
    back_to_admin($eventId, '対象が不正です。', 'ng');
}

// IDOR対策: 指定 customer_id が「このイベントの参加者」であることを Stripe 側で検証する。
// （全テナントが単一 Stripe を共有するため、ID だけでは他テナントの顧客も指せてしまう。）
if (find_event_participant_by_customer($eventId, $account, $customerId) === null) {
    audit_log('authz.deny', ['action' => 'attend', 'tenant' => $tenant['id'], 'event' => $eventId]);
    back_to_admin($eventId, '対象の参加者が見つかりません。', 'ng');
}

init_stripe();
$opts = stripe_opts($account);

try {
    \Stripe\Customer::update($customerId, [
        'metadata' => [
            'attended' => $attend ? '1' : '',
            'attended_at' => $attend ? (string) time() : '',
        ],
    ], $opts);
    back_to_admin($eventId, $attend ? '出席確認しました。' : '出席を取り消しました。', 'ok');
} catch (\Throwable $ex) {
    error_log('出席状態の更新失敗: ' . $ex->getMessage());
    back_to_admin($eventId, '更新に失敗しました: ' . $ex->getMessage(), 'ng');
}
