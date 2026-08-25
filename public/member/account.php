<?php

/**
 * アカウント設定：ログインIDの変更＋パスワード変更への導線。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();
$memberId = (string) $member['id'];
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ((string) ($_POST['action'] ?? '') === 'login_id') {
        $r = change_member_login_id($memberId, (string) ($_POST['login_id'] ?? ''));
        $msg = $r['message'];
        $msgType = $r['ok'] ? 'ok' : 'ng';
        if ($r['ok']) {
            $member = find_member_by_id($memberId); // 表示を最新化
        }
    }
}

$token = csrf_token();
$pageTitle = 'ログインID・パスワード';
$showLogout = true;
$hideBrand = true;
require __DIR__ . '/_header.php';
?>
<h1 style="font-size:1.4rem;margin:0 0 4px;">ログインID・パスワード</h1>
<p class="muted" style="margin:0 0 12px;"><a href="/member/dashboard.php">← マイページ</a></p>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
    <div class="card__title" style="color:var(--coral-d);">ログインID</div>
    <p class="muted" style="margin-top:0;">ログイン時に使うIDです。半角英数字と <code>_ . -</code> の4〜20文字。</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="login_id">
        <label>新しいログインID</label>
        <input type="text" name="login_id" value="<?= e((string) $member['login_id']) ?>" maxlength="20"
               pattern="[A-Za-z0-9_.\-]{4,20}" autocapitalize="off" autocomplete="username" style="max-width:280px;">
        <p style="margin-top:14px;"><button type="submit" class="btn">ログインIDを変更</button></p>
    </form>
    <p class="muted" style="font-size:.82rem;margin:6px 0 0;">現在のログインID：<code><?= e((string) $member['login_id']) ?></code></p>
</div>

<div class="card">
    <div class="card__title" style="color:var(--coral-d);">パスワード</div>
    <p class="muted" style="margin-top:0;">パスワードの変更は専用ページで行えます。</p>
    <p><a class="btn btn--ghost" href="/member/change_password.php">パスワードを変更する</a></p>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
