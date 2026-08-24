<?php

/**
 * 会員のパスワード変更。
 * - 初回（must_change_pw=1）：仮パスワードからの強制変更。現在のパスワード入力は不要。
 * - 通常：現在のパスワードを確認のうえ変更。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

// PW変更中も入れるように allowDuringPwChange=true
$member = require_member(true, true);
$forced = (int) ($member['must_change_pw'] ?? 0) === 1;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!$forced) {
        $current = (string) ($_POST['current_password'] ?? '');
        if (!password_verify($current, $member['password_hash'])) {
            $error = '現在のパスワードが違います。';
        }
    }
    if ($error === '' && $new !== $confirm) {
        $error = '確認用パスワードが一致しません。';
    }
    if ($error === '') {
        try {
            update_member_password($member['id'], $new);
            audit_log('member.password_change', ['member' => $member['id'], 'forced' => $forced ? 1 : 0]);
            header('Location: /member/dashboard.php?msg=' . rawurlencode('パスワードを変更しました。') . '&type=ok');
            exit;
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage(); // 強度不足など
        }
    }
}

$token = csrf_token();
$pageTitle = 'パスワード変更';
$showLogout = true;
require __DIR__ . '/_header.php';
?>
<h1><?= $forced ? '初回パスワードの設定' : 'パスワード変更' ?></h1>
<?php if ($forced): ?>
    <p class="muted">安全のため、仮パスワードから<strong>ご自身のパスワードへ変更</strong>してください。変更するまで他の画面はご利用いただけません。</p>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="flash flash--ng"><?= e($error) ?>
        <div style="font-size:.82rem;margin-top:6px;font-weight:400;">パスワードは<strong>8文字以上</strong>で、新しいパスワードと確認用が一致している必要があります。</div>
    </div>
<?php endif; ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
    <?php if (!$forced): ?>
        <label>現在のパスワード</label>
        <input type="password" name="current_password" required autocomplete="current-password">
    <?php endif; ?>
    <label>新しいパスワード（8文字以上）</label>
    <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
    <label>新しいパスワード（確認）</label>
    <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
    <p style="margin-top:16px;"><button type="submit" class="btn">パスワードを変更</button></p>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
