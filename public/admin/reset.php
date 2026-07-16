<?php

/**
 * パスワード再設定の実行。メールのリンク（?token=...）から新しいパスワードを設定する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));
$error = '';
$done = false;

$valid = $token !== '' ? find_valid_reset($token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $new = (string) ($_POST['new_password'] ?? '');
    if ($valid === null) {
        $error = 'リンクが無効か、有効期限が切れています。お手数ですが再度お申し込みください。';
    } else {
        try {
            if (consume_password_reset($token, $new)) {
                $done = true;
            } else {
                $error = 'リンクが無効か、有効期限が切れています。';
            }
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage(); // パスワード強度不足など
        }
    }
}

$tk = csrf_token();
require __DIR__ . '/_auth_header.php';
?>
<h1>新しいパスワードの設定</h1>

<?php if ($done): ?>
    <div class="card"><p>パスワードを変更しました。新しいパスワードでログインしてください。</p></div>
    <p><a class="btn" href="login.php" style="display:inline-block;width:auto;text-decoration:none;">ログインへ</a></p>
<?php elseif ($valid === null): ?>
    <div class="err">リンクが無効か、有効期限が切れています。</div>
    <p class="muted"><a href="forgot.php">もう一度申し込む</a></p>
<?php else: ?>
    <?php if ($error !== ''): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= e($tk) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label>新しいパスワード（8文字以上）</label>
        <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
        <p style="margin-top:14px;"><button type="submit" class="btn">パスワードを設定</button></p>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/_auth_footer.php'; ?>
