<?php

/**
 * 参加者名簿の CSV ダウンロード（要 ID＋パスワード）。
 * Excel 等での名簿管理・当日受付に使える。Excel での文字化け回避のため UTF-8 BOM を付与。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();

$eventId = (string) ($_GET['event_id'] ?? '');
$event = $eventId !== '' ? find_event($eventId) : null;

if ($event === null || $event['tenant_id'] !== $tenant['id']) {
    audit_log('authz.deny', ['action' => 'csv.export', 'tenant' => $tenant['id'], 'event' => $eventId]);
    http_response_code(404);
    exit('イベントが見つかりません。');
}

try {
    $account = stripe_resolve_tenant($tenant);
    $participants = fetch_event_participants($eventId, $account);
    audit_log('csv.export', ['tenant' => $tenant['id'], 'event' => $eventId, 'count' => count($participants)]);
} catch (\Throwable $ex) {
    http_response_code(502);
    error_log('CSV 用名簿取得失敗: ' . $ex->getMessage());
    exit('名簿の取得に失敗しました。時間をおいて再度お試しください。');
}

$filename = 'participants_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $eventId) . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM（Excel 文字化け対策）

fputcsv($out, ['申込日時', 'お名前', 'メール', '電話', '人数', '支払方法', '支払額', '返金額', '状態', '出席', '備考', 'ID']);

foreach ($participants as $p) {
    $isOnsite = ($p['payment_type'] ?? 'prepay') === 'onsite';
    if ($isOnsite) {
        $method = '当日';
        $status = !empty($p['collected']) ? '受領済み' : '当日支払い・未収';
        $idRef = $p['customer_id'];
    } else {
        $method = '事前';
        if ($p['fully_refunded']) {
            $status = '全額返金（キャンセル）';
        } elseif ($p['amount_refunded'] > 0) {
            $status = '一部返金';
        } else {
            $status = '支払い済み';
        }
        $idRef = $p['payment_intent'];
    }

    // 参加者由来の文字列（氏名・メール・電話・備考）は数式インジェクション対策で無害化する。
    fputcsv($out, [
        date('Y-m-d H:i', $p['created']),
        csv_cell($p['name']),
        csv_cell($p['email']),
        csv_cell($p['phone']),
        (int) $p['party_size'] . '名',
        $method,
        format_amount($p['amount'], $p['currency']),
        format_amount($p['amount_refunded'], $p['currency']),
        $status,
        !empty($p['attended']) ? '出席済み' : '',
        csv_cell($p['note']),
        csv_cell($idRef),
    ]);
}

fclose($out);
