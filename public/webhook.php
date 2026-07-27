<?php

/**
 * Stripe Webhook 受信エンドポイント。
 *
 * 入会金決済（checkout.session.completed / mode=payment）を受けて、会員化＋ID/PW発行を行う。
 * 署名は「生ボディ」で検証し、event.id で冪等化する。会員化の二重発行防止は payment 層の
 * claim-first（payments.stripe_checkout_session_id UNIQUE）でも担保している。
 * Webhook を取りこぼしても bin/reconcile.php（cron）が同じ処理で救済する。
 *
 * ローカルでの試し方（Stripe CLI）:
 *   stripe listen --forward-to localhost:8000/webhook.php
 *   stripe trigger checkout.session.completed
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

try {
    switch ($event->type) {
        case 'checkout.session.completed':
            $session = $event->data->object;
            // 入会金以外（将来別用途を足す場合）はメタデータで振り分け可能にしておく。
            $purpose = (string) ($session->metadata->purpose ?? 'join_fee');
            if ($purpose === 'join_fee') {
                // event.id で冪等化（重複配信は無視）。実処理側も claim-first で二重発行を防ぐ。
                if (record_stripe_event_once((string) $event->id, (string) $event->type)) {
                    provision_member_from_checkout_session(normalize_checkout_session($session));
                }
            }
            break;

        default:
            // 未対応イベントは無視（200 を返す）。
            break;
    }
} catch (\Throwable $e) {
    // 失敗しても 500 を返すと Stripe が再送してくれる（冪等なので安全）。
    error_log('webhook provisioning error: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

http_response_code(200);
echo 'ok';
