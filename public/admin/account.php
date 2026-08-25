<?php

/**
 * アカウント設定：表示名の変更、パスワードの変更。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'name') {
        $name = trim((string) ($_POST['display_name'] ?? ''));
        update_tenant_display_name($tenant['id'], mb_substr($name, 0, 100));
        $msg = '表示名を更新しました。';
        $tenant = find_tenant_by_id($tenant['id']);
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify($current, $tenant['password_hash'])) {
            $msg = '現在のパスワードが違います。';
            $msgType = 'ng';
        } else {
            try {
                update_tenant_password($tenant['id'], $new);
                audit_log('account.password_change', ['tenant' => $tenant['id']]);
                $msg = 'パスワードを変更しました。';
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $msgType = 'ng';
            }
        }
    }
}

$token = csrf_token();
$pageTitle = 'アカウント設定';
$pageSub = $tenant['email'];
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?>
    <div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card__title">表示名</div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="name">
        <input type="text" name="display_name" maxlength="100" value="<?= e($tenant['display_name']) ?>">
        <p style="margin-top:14px;"><button type="submit" class="btn">表示名を更新</button></p>
    </form>
</div>

<div class="card">
    <div class="card__title">パスワード変更</div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="password">
        <label>現在のパスワード</label>
        <input type="password" name="current_password" required autocomplete="current-password">
        <label>新しいパスワード（8文字以上）</label>
        <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
        <p style="margin-top:14px;"><button type="submit" class="btn">パスワードを変更</button></p>
    </form>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
