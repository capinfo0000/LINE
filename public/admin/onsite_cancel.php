<?php

/**
 * 当日支払い申込の取消。管理画面からの POST のみを受ける。
 * 当日申込は課金なしの Stripe 顧客として記録しているため、その顧客を削除する。
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
    back_to_admin($eventId, '取消対象が不正です。', 'ng');
}

// IDOR対策: 指定 customer_id が「このイベントの当日支払い参加者」であることを Stripe 側で検証する。
$target = find_event_participant_by_customer($eventId, $account, $customerId);
if ($target === null || ($target['payment_type'] ?? '') !== 'onsite') {
    audit_log('authz.deny', ['action' => 'onsite_cancel', 'tenant' => $tenant['id'], 'event' => $eventId]);
    back_to_admin($eventId, '取消対象の参加者が見つかりません。', 'ng');
}

init_stripe();
$opts = stripe_opts($account);

try {
    \Stripe\Customer::retrieve($customerId, $opts)->delete([], $opts);
    back_to_admin($eventId, '当日支払いの申込を取り消しました。', 'ok');
} catch (\Throwable $ex) {
    error_log('当日申込の取消失敗: ' . $ex->getMessage());
    back_to_admin($eventId, '取消に失敗しました: ' . $ex->getMessage(), 'ng');
}
