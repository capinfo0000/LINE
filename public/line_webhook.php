<?php

/**
 * 公式LINE（Messaging API）Webhook 受信エンドポイント。
 *
 * 署名（X-Line-Signature）を生ボディで検証し、各イベントを状態機械（line_handle_event）に渡す。
 * ユーザー操作への応答は reply（無料）で返す。時間差の Push（リマインド・決済リンク・ID/PW配布）は
 * cron や承認アクションから行う。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

if (!line_verify_signature($body, $signature)) {
    http_response_code(400);
    exit;
}

$data = json_decode($body, true);
$events = is_array($data['events'] ?? null) ? $data['events'] : [];

foreach ($events as $event) {
    try {
        $messages = line_handle_event($event);
        $replyToken = (string) ($event['replyToken'] ?? '');
        if ($messages !== [] && $replyToken !== '') {
            line_reply($replyToken, $messages);
        }
    } catch (\Throwable $e) {
        error_log('line_webhook handle error: ' . $e->getMessage());
        // 個別イベントの失敗で全体を落とさない。
    }
}

http_response_code(200);
echo 'ok';
