<?php

/**
 * Stripe Webhook 受信エンドポイント（任意・推奨）。
 *
 * 支払い完了などのイベントを Stripe から受け取り、署名検証のうえ
 * ローカルのログファイル（logs/payments.log）に記録します。
 * ※ カード情報は含まれません。記録するのは「イベント名・金額・メール・Stripeの決済ID」のみ。
 * ※ DB は使いません。正式な参加者名簿は Stripe ダッシュボードが正です。
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

/**
 * プラン課金（サブスクリプション）のイベントを処理して tenant.plan を同期する。
 * これらはプラットフォーム本体のアカウントのイベント（接続アカウントではない）。
 */
switch ($event->type) {
    case 'checkout.session.completed':
        $session = $event->data->object;

        if (($session->mode ?? '') === 'subscription') {
            // プラン課金の完了：tenant にプランと課金顧客を反映
            $tenantId = $session->client_reference_id
                ?? ($session->metadata->tenant_id ?? '');
            $plan = $session->metadata->plan ?? '';
            if ($tenantId !== '' && $plan !== '' && find_tenant_by_id($tenantId) !== null) {
                set_tenant_plan($tenantId, $plan);
                if (!empty($session->customer)) {
                    set_tenant_billing_customer($tenantId, (string) $session->customer);
                }
            }
        }
        break;

    case 'customer.subscription.updated':
        // プラン変更（アップグレード/ダウングレード）を価格IDから反映
        $sub = $event->data->object;
        $tenant = !empty($sub->customer) ? find_tenant_by_billing_customer((string) $sub->customer) : null;
        if ($tenant !== null) {
            $priceId = $sub->items->data[0]->price->id ?? '';
            $plan = $priceId !== '' ? plan_for_price_id($priceId) : null;
            // 解約予定/失効ステータスは無料へ
            if (in_array($sub->status ?? '', ['canceled', 'unpaid', 'incomplete_expired'], true)) {
                set_tenant_plan($tenant['id'], 'free');
            } elseif ($plan !== null) {
                set_tenant_plan($tenant['id'], $plan);
            }
        }
        break;

    case 'customer.subscription.deleted':
        // 解約：無料プランに戻す
        $sub = $event->data->object;
        $tenant = !empty($sub->customer) ? find_tenant_by_billing_customer((string) $sub->customer) : null;
        if ($tenant !== null) {
            set_tenant_plan($tenant['id'], 'free');
        }
        break;
}

http_response_code(200);
echo 'ok';
