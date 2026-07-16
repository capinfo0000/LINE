<?php

/**
 * Stripe Webhook 受信エンドポイント（Phase 0 時点は土台のみ）。
 *
 * 署名検証だけ行い 200 を返す。入会金決済（checkout.session.completed / mode=payment）による
 * 会員化＋ID/PW発行の処理は Phase 2 でここに実装する（`stripe_events` による冪等化つき）。
 *
 * ローカルでの試し方（Stripe CLI）:
 *   stripe listen --forward-to localhost:8000/webhook.php
 * 表示された whsec_... を .env の STRIPE_WEBHOOK_SECRET に設定してください。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$secret = env('STRIPE_WEBHOOK_SECRET');
if ($secret === null) {
    http_response_code(500);
    error_log('STRIPE_WEBHOOK_SECRET が未設定です。');
    exit;
}

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
} catch (\UnexpectedValueException $e) {
    http_response_code(400); // 不正なペイロード
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400); // 署名検証失敗
    exit;
}

// TODO(Phase 2): checkout.session.completed(mode=payment) を受けて
//   - stripe_events で冪等化
//   - members を active 化し、発行ID＋仮PW（must_change_pw=1）を発行
//   - Bot 経由で ID/PW＋OpenChat URL を配布
// を実装する。現時点では受領して 200 を返すのみ。

http_response_code(200);
echo 'ok';
