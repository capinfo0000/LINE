<?php

/**
 * 初期セットアップウィザード（ブラウザ）。
 *
 * ブラウザで必要情報を入力すると、.env を生成し、DB を初期化し、運営管理者を作成します。
 * 完了後はこのファイル自身を削除します（インストーラを残さない）。
 *
 * 【安全ガード（三重）】
 *  1) data/.installed ロックがあれば実行しない
 *  2) 運営者アカウントが既に存在すれば実行しない（乗っ取り防止）
 *  3) 完了時にこのファイルを自己削除（＋ git 再取得時もガード1/2で拒否）
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$lockPath = APP_ROOT . '/data/.installed';
$selfPath = __FILE__;

/** 既にインストール済みか（ロック or 運営者存在）。 */
function install_is_done(string $lockPath): bool
{
    if (is_file($lockPath)) {
        return true;
    }
    try {
        return (int) db()->query('SELECT COUNT(*) FROM tenants')->fetchColumn() > 0;
    } catch (\Throwable $e) {
        // DBが読めないときに「未インストール」と判定すると、障害時にインストーラが
        // 誰にでも開いてしまう（＝管理者を新規作成され .env を上書きされる）。
        // 判定できない場合はインストール済みとみなして閉じる（フェイルクローズ）。
        return true;
    }
}

/** .env 用に値を安全な1行へ整形（改行除去・ダブルクオート囲み）。 */
function env_line(string $key, string $value): string
{
    $value = str_replace(["\r", "\n"], '', $value);
    $value = str_replace('"', '\"', $value);
    return $key . '="' . $value . '"';
}

$done = false;
$errors = [];
$alreadyInstalled = install_is_done($lockPath);

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $appBaseUrl   = trim((string) ($_POST['app_base_url'] ?? ''));
    $adminEmail   = trim((string) ($_POST['admin_email'] ?? ''));
    $adminPass    = (string) ($_POST['admin_password'] ?? '');
    $stripeKey    = trim((string) ($_POST['stripe_secret_key'] ?? ''));
    $stripeWebhook = trim((string) ($_POST['stripe_webhook_secret'] ?? ''));
    $joinFee      = trim((string) ($_POST['join_fee_amount'] ?? '2000'));
    $lineSecret   = trim((string) ($_POST['line_channel_secret'] ?? ''));
    $lineToken    = trim((string) ($_POST['line_channel_access_token'] ?? ''));
    $zoomAccount  = trim((string) ($_POST['zoom_account_id'] ?? ''));
    $zoomClient   = trim((string) ($_POST['zoom_client_id'] ?? ''));
    $zoomSecret   = trim((string) ($_POST['zoom_client_secret'] ?? ''));
    $mailFrom     = trim((string) ($_POST['mail_from'] ?? ''));
    $mailFromName = trim((string) ($_POST['mail_from_name'] ?? 'Enlink'));

    // 検証
    if ($appBaseUrl === '' || !preg_match('#^https?://#i', $appBaseUrl)) {
        $errors[] = '公開URL（APP_BASE_URL）は http(s):// から始まる形式で入力してください。';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '運営者メールアドレスの形式が正しくありません。';
    }
    try {
        assert_password_strength($adminPass);
    } catch (\Throwable $e) {
        $errors[] = '運営者パスワード：' . $e->getMessage();
    }
    if ($joinFee === '' || !ctype_digit($joinFee)) {
        $joinFee = '2000';
    }
    if ($mailFrom !== '' && !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '送信元メールアドレスの形式が正しくありません。';
    }

    if ($errors === []) {
        // APP_KEY 自動生成
        $appKey = base64_encode(random_bytes(32));
        $baseUrl = rtrim($appBaseUrl, '/');
        if ($mailFrom === '') {
            $host = parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost';
            $mailFrom = 'no-reply@' . $host;
        }

        $lines = [
            '# Enlink 設定（セットアップウィザードにより自動生成）',
            env_line('APP_BASE_URL', $baseUrl),
            env_line('APP_KEY', $appKey),
            env_line('JOIN_FEE_AMOUNT', $joinFee),
            env_line('ALLOW_SIGNUP', '0'),
            env_line('TRUSTED_PROXY', '0'),
            env_line('MAIL_FROM', $mailFrom),
            env_line('MAIL_FROM_NAME', $mailFromName !== '' ? $mailFromName : 'Enlink'),
            '',
            '# Stripe（入会金決済）',
            env_line('STRIPE_SECRET_KEY', $stripeKey),
            env_line('STRIPE_WEBHOOK_SECRET', $stripeWebhook),
            '',
            '# 公式LINE（Messaging API）',
            env_line('LINE_CHANNEL_SECRET', $lineSecret),
            env_line('LINE_CHANNEL_ACCESS_TOKEN', $lineToken),
            '',
            '# Zoom（Server-to-Server OAuth・任意）',
            env_line('ZOOM_ACCOUNT_ID', $zoomAccount),
            env_line('ZOOM_CLIENT_ID', $zoomClient),
            env_line('ZOOM_CLIENT_SECRET', $zoomSecret),
            '',
        ];
        $envContent = implode("\n", $lines);

        $envPath = APP_ROOT . '/.env';
        if (@file_put_contents($envPath, $envContent, LOCK_EX) === false) {
            $errors[] = '.env の書き込みに失敗しました（プロジェクト直下の書き込み権限を確認してください）。';
        } else {
            @chmod($envPath, 0600);
            // 直後の処理で確実に使えるよう、現在のプロセスにも反映
            putenv('DB_PATH=' . (getenv('DB_PATH') ?: APP_ROOT . '/data/app.sqlite'));

            try {
                db(); // マイグレーション実行
                create_tenant($adminEmail, $adminPass, '運営管理者', true);

                // インストール済みロック
                @file_put_contents($lockPath, date('c') . " installed\n");
                @chmod($lockPath, 0600);

                // 自己削除（残さない）。失敗してもロック＋運営者存在ガードで再実行は拒否される。
                @unlink($selfPath);

                $done = true;
                audit_log('install.completed', ['admin' => mask_email_for_log($adminEmail)]);
            } catch (\Throwable $e) {
                $errors[] = '初期化に失敗しました：' . $e->getMessage();
            }
        }
    }
}

$token = $alreadyInstalled ? '' : csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>初期セットアップ - Enlink</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="container container--narrow">
    <div class="brandbar">Enlink セットアップ</div>

<?php if ($alreadyInstalled): ?>
    <div class="card">
        <h1>セットアップは完了しています</h1>
        <p>既に初期設定が済んでいるため、このページは無効です。安全のため、サーバー上に <code>public/install.php</code> が残っている場合は削除してください。</p>
        <p><a class="btn" href="/admin/login">運営ログインへ</a></p>
    </div>
<?php elseif ($done): ?>
    <div class="card">
        <h1>✅ セットアップ完了</h1>
        <p><code>.env</code> の生成・データベース初期化・運営管理者の作成が完了しました。
           このセットアップ画面は削除済みです。</p>
        <p><a class="btn" href="/admin/login">運営ログインへ</a></p>
    </div>
    <div class="card">
        <div class="card__title">次にやること（任意・後からでOK）</div>
        <ul>
            <li>Stripe Webhook：<code><?= e(rtrim((string) env('APP_BASE_URL'), '/')) ?>/webhook.php</code>（イベント <code>checkout.session.completed</code>）を登録し、署名シークレットを運営者に設定</li>
            <li>LINE Webhook：<code><?= e(rtrim((string) env('APP_BASE_URL'), '/')) ?>/line_webhook.php</code> を設定</li>
            <li>cron：<code>bin/reconcile.php</code>（10分）/<code>bin/remind.php</code>（5分）/<code>bin/recommend.php</code>（週次）</li>
            <li>法務ページ（特商法・規約・プライバシー）の ［ ］ を実情報に置換</li>
        </ul>
        <p class="muted">これらは後から <code>.env</code> の編集や運営コンソールでも設定できます。</p>
    </div>
<?php else: ?>
    <div class="card">
        <h1>初期セットアップ</h1>
        <p class="muted">必要な情報を入力すると、<code>.env</code> の作成・DB初期化・運営者アカウント作成をまとめて行います。完了後この画面は自動的に削除されます。</p>
        <p class="muted">未入力でも進められます（Stripe/LINE/Zoom は後から設定可）。まずは<strong>運営ログイン用の情報</strong>だけでもOKです。</p>
    </div>

    <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

        <div class="card">
            <div class="card__title">基本（必須）</div>
            <label>公開URL（このサイトのURL・https推奨）</label>
            <input type="url" name="app_base_url" required placeholder="https://xxxx.coreserver.jp" value="<?= e((string) ($_POST['app_base_url'] ?? '')) ?>">
            <label>運営者メールアドレス（ログインID）</label>
            <input type="email" name="admin_email" required value="<?= e((string) ($_POST['admin_email'] ?? '')) ?>">
            <label>運営者パスワード（8文字以上）</label>
            <input type="password" name="admin_password" required minlength="8" autocomplete="new-password">
        </div>

        <div class="card">
            <div class="card__title">入会金・メール</div>
            <label>入会金（円）</label>
            <input type="number" name="join_fee_amount" min="1" value="<?= e((string) ($_POST['join_fee_amount'] ?? '2000')) ?>">
            <label>送信元メールアドレス（空欄可・自動設定）</label>
            <input type="email" name="mail_from" placeholder="no-reply@ドメイン" value="<?= e((string) ($_POST['mail_from'] ?? '')) ?>">
            <label>送信者名</label>
            <input type="text" name="mail_from_name" value="<?= e((string) ($_POST['mail_from_name'] ?? 'Enlink')) ?>">
        </div>

        <div class="card">
            <div class="card__title">Stripe（入会金決済・任意／後で設定可）</div>
            <label>Stripe シークレットキー（sk_live_… / テストは sk_test_…）</label>
            <input type="text" name="stripe_secret_key" autocomplete="off" value="<?= e((string) ($_POST['stripe_secret_key'] ?? '')) ?>">
            <label>Stripe Webhook 署名シークレット（whsec_…）</label>
            <input type="text" name="stripe_webhook_secret" autocomplete="off" value="<?= e((string) ($_POST['stripe_webhook_secret'] ?? '')) ?>">
        </div>

        <div class="card">
            <div class="card__title">公式LINE（任意／後で設定可）</div>
            <label>チャネルシークレット</label>
            <input type="text" name="line_channel_secret" autocomplete="off" value="<?= e((string) ($_POST['line_channel_secret'] ?? '')) ?>">
            <label>チャネルアクセストークン</label>
            <input type="text" name="line_channel_access_token" autocomplete="off" value="<?= e((string) ($_POST['line_channel_access_token'] ?? '')) ?>">
        </div>

        <div class="card">
            <div class="card__title">Zoom（任意／未設定なら手動URL運用）</div>
            <label>Account ID</label>
            <input type="text" name="zoom_account_id" autocomplete="off" value="<?= e((string) ($_POST['zoom_account_id'] ?? '')) ?>">
            <label>Client ID</label>
            <input type="text" name="zoom_client_id" autocomplete="off" value="<?= e((string) ($_POST['zoom_client_id'] ?? '')) ?>">
            <label>Client Secret</label>
            <input type="text" name="zoom_client_secret" autocomplete="off" value="<?= e((string) ($_POST['zoom_client_secret'] ?? '')) ?>">
        </div>

        <p><button type="submit" class="btn">この内容でセットアップする</button></p>
    </form>
<?php endif; ?>
</div>
</body>
</html>
