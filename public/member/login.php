<?php

/**
 * 会員ログイン（発行ログインID＋パスワード）。
 * 初回ログイン時は must_change_pw によりPW変更ページへ誘導される。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $loginId = trim((string) ($_POST['login_id'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // 総当たり対策：発行ID単位（標的型）と IP 単位（ID横断スプレー）の両方で失敗回数を制限。
    if (recent_failed_logins($loginId) >= 5 || recent_failed_logins_by_ip(client_ip()) >= 20) {
        audit_log('member.login.blocked', ['login_id' => $loginId]);
        $error = '試行回数が多すぎます。しばらく時間をおいてからお試しください。';
    } elseif (!captcha_verify($_POST['cf-turnstile-response'] ?? null, true)) {
        $error = '認証（CAPTCHA）に失敗しました。もう一度お試しください。';
    } elseif (login_member($loginId, $password)) {
        clear_failed_logins($loginId);
        audit_log('member.login.ok', ['login_id' => $loginId]);
        // 初回PW強制変更が必要なら変更ページへ、そうでなければダッシュボードへ。
        $m = current_member();
        if ($m !== null && (int) ($m['must_change_pw'] ?? 0) === 1) {
            header('Location: /member/change_password.php');
        } else {
            header('Location: /member/dashboard.php');
        }
        exit;
    } else {
        record_failed_login($loginId);
        audit_log('member.login.fail', ['login_id' => $loginId]);
        $error = 'ログインIDまたはパスワードが違います。';
    }
}

// すでにログイン済みなら会員トップへ
if (current_member() !== null) {
    header('Location: /member/dashboard.php');
    exit;
}

$token = csrf_token();
$pageTitle = '会員ログイン';
require __DIR__ . '/_header.php';
?>
<h1>会員ログイン</h1>
<?php if ($error !== ''): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
    <label>ログインID</label>
    <input type="text" name="login_id" required autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="例: el8f3k9q2m">
    <label>パスワード</label>
    <input type="password" name="password" required autocomplete="current-password">
    <?= captcha_widget_html() ?>
    <p style="margin-top:16px;"><button type="submit" class="btn">ログイン</button></p>
</form>
<p class="muted"><a href="/member/forgot.php">パスワードを忘れた場合</a></p>
<p class="muted">ログインID・パスワードは入会手続き完了時にお渡ししています。</p>
<?php require __DIR__ . '/_footer.php'; ?>
