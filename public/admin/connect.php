<?php

/**
 * Stripe Connect 連携（主催者が自分の Stripe アカウントを接続して、PII・入金を物理分離する）。
 *
 * - ?action=start      … Stripe の OAuth 認可画面へリダイレクト
 * - 戻り (?code=&state=) … 認可コードをアクセストークンに交換し、接続アカウントID(acct_...)を保存
 * - ?action=disconnect … 接続を解除（自アプリ側の保存を消し、Stripe 側の認可も解除を試みる）
 *
 * 認証済みテナント専用。state は CSRF（セッション）で検証する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();

/** 結果メッセージ付きでダッシュボードへ戻す。 */
function back_to_dashboard(string $msg, string $type): never
{
    $q = http_build_query(['msg' => $msg, 'type' => $type]);
    header('Location: dashboard.php?' . $q, true, 303);
    exit;
}

if (!connect_enabled()) {
    back_to_dashboard('Stripe 連携は現在利用できません（管理者の設定待ち）。', 'ng');
}

init_stripe();

$action = (string) ($_GET['action'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$oauthError = (string) ($_GET['error'] ?? '');

// ---- 1) 連携開始: Stripe の認可画面へ ----
if ($action === 'start') {
    session_boot();
    $st = bin2hex(random_bytes(16));
    $_SESSION['stripe_connect_state'] = $st;

    $params = http_build_query([
        'response_type' => 'code',
        'client_id' => env('STRIPE_CONNECT_CLIENT_ID'),
        'scope' => 'read_write',
        'state' => $st,
        'redirect_uri' => base_url() . '/admin/connect.php',
        'stripe_user[email]' => $tenant['email'] ?? '',
    ]);
    header('Location: https://connect.stripe.com/oauth/authorize?' . $params, true, 303);
    exit;
}

// ---- 2) 連携解除 ----
if ($action === 'disconnect') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $acct = (string) ($tenant['stripe_account_id'] ?? '');
    if ($acct !== '') {
        try {
            \Stripe\OAuth::deauthorize([
                'client_id' => env('STRIPE_CONNECT_CLIENT_ID'),
                'stripe_user_id' => $acct,
            ]);
        } catch (\Throwable $e) {
            // Stripe 側の解除に失敗してもアプリ側の紐付けは外す（再接続で復帰可能）
            error_log('Connect 解除のStripe通信失敗: ' . $e->getMessage());
        }
    }
    set_tenant_stripe_account($tenant['id'], null);
    back_to_dashboard('Stripe 接続を解除しました。', 'ok');
}

// ---- 3) 認可コードのコールバック ----
if ($oauthError !== '') {
    back_to_dashboard('Stripe 連携がキャンセルされました。', 'ng');
}

if ($code !== '') {
    session_boot();
    $expected = (string) ($_SESSION['stripe_connect_state'] ?? '');
    unset($_SESSION['stripe_connect_state']);
    if ($expected === '' || !hash_equals($expected, $state)) {
        back_to_dashboard('連携の検証に失敗しました（state 不一致）。もう一度お試しください。', 'ng');
    }
    try {
        $resp = \Stripe\OAuth::token([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);
        $acct = (string) ($resp->stripe_user_id ?? '');
        if ($acct === '') {
            back_to_dashboard('接続アカウントの取得に失敗しました。', 'ng');
        }
        set_tenant_stripe_account($tenant['id'], $acct);
        back_to_dashboard('Stripe を接続しました。今後の決済・名簿はあなたの Stripe アカウントで分離されます。', 'ok');
    } catch (\Throwable $e) {
        error_log('Connect トークン交換失敗: ' . $e->getMessage());
        back_to_dashboard('Stripe 連携に失敗しました。時間をおいて再度お試しください。', 'ng');
    }
}

back_to_dashboard('不正なリクエストです。', 'ng');
