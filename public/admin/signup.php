<?php

/**
 * 主催者サインアップ（オープン登録）。
 * メールアドレス＋パスワードを入力すれば誰でもアカウントを作成できる。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$error = '';

// 新規登録の受付可否（単独運営に切り替える場合は .env で ALLOW_SIGNUP=0 にして閉じられる）。
$signupOpen = env('ALLOW_SIGNUP', '1') !== '0';

if ($signupOpen && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $email = (string) ($_POST['email'] ?? '');
    $name = (string) ($_POST['display_name'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    // 濫用対策: 同一IPからの登録は一定時間内の回数を制限する。
    if (!rate_limit_check('signup', 5, 3600)) {
        $error = '登録の試行が多すぎます。しばらく時間をおいて再度お試しください。';
    } elseif (!captcha_verify($_POST['cf-turnstile-response'] ?? null, true)) {
        $error = '認証（CAPTCHA）に失敗しました。もう一度お試しください。';
    } else {
        try {
            create_tenant($email, $password, $name);
            audit_log('signup', ['email' => mask_email_for_log($email)]);
            login_tenant($email, $password);
            header('Location: dashboard');
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// すでにログイン済みならダッシュボードへ
if (current_tenant() !== null) {
    header('Location: dashboard');
    exit;
}

$token = csrf_token();
require __DIR__ . '/_auth_header.php';
?>
<h1>運営アカウント登録</h1>
<?php if (!$signupOpen): ?>
    <div class="card"><p style="margin:0;">現在、新規登録の受付を停止しています。アカウントについては運営者へお問い合わせください。</p></div>
    <p class="muted">すでにアカウントをお持ちですか？ <a href="login">ログイン</a></p>
<?php else: ?>
<p class="muted">メールアドレスとパスワードを入力するだけで、すぐに始められます。</p>
<?php if ($error !== ''): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
    <label>表示名（運営者名）</label>
    <input type="text" name="display_name" maxlength="100" placeholder="運営 太郎">
    <label>メールアドレス</label>
    <input type="email" name="email" required autocomplete="email">
    <label>パスワード（8文字以上）</label>
    <input type="password" name="password" required minlength="8" autocomplete="new-password">
    <?= captcha_widget_html() ?>
    <p style="margin-top:16px;"><button type="submit" class="btn">登録してはじめる</button></p>
</form>
<p class="muted">すでにアカウントをお持ちですか？ <a href="login">ログイン</a></p>
<?php endif; ?>
<?php require __DIR__ . '/_auth_footer.php'; ?>
