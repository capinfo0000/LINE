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
            // 買い切り(join_fee) / サブスク(subscription) の初回決済 → 会員化＋ID/PW発行。
            $purpose = (string) ($session->metadata->purpose ?? 'join_fee');
            if ($purpose === 'join_fee' || $purpose === 'subscription') {
                // event.id で冪等化（重複配信は無視）。実処理側も claim-first で二重発行を防ぐ。
                if (record_stripe_event_once((string) $event->id, (string) $event->type)) {
                    provision_member_from_checkout_session(normalize_checkout_session($session));
                }
            }
            break;

        case 'invoice.paid':
        case 'invoice.payment_succeeded':
            // 毎月の課金成功 → 会員を有効維持＋紹介者へ継続ボーナス（invoice単位で冪等）。
            if (record_stripe_event_once((string) $event->id, (string) $event->type)) {
                $inv = $event->data->object;
                handle_invoice_paid([
                    'id'           => (string) ($inv->id ?? ''),
                    'customer'     => (string) ($inv->customer ?? ''),
                    'subscription' => (string) ($inv->subscription ?? ''),
                ]);
            }
            break;

        case 'invoice.payment_failed':
            // 未払い → subscription_status を past_due に（停止は subscription.deleted で行う）。
            if (record_stripe_event_once((string) $event->id, (string) $event->type)) {
                $inv = $event->data->object;
                handle_subscription_status((string) ($inv->customer ?? ''), 'past_due');
            }
            break;

        case 'customer.subscription.deleted':
        case 'customer.subscription.updated':
            // 解約/失効 → 会員停止。past_due 等は状態のみ更新。
            if (record_stripe_event_once((string) $event->id, (string) $event->type)) {
                $sub = $event->data->object;
                handle_subscription_status((string) ($sub->customer ?? ''), (string) ($sub->status ?? ''), (string) ($sub->id ?? ''));
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
