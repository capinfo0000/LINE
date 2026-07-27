<?php

/**
 * 招待コードの発行（プラットフォーム管理者のみ）。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$admin = require_admin_tenant();
$tenant = $admin; // シェルのサイドバー表示用

$newCode = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $email = trim((string) ($_POST['email'] ?? ''));
    $newCode = create_invite($admin['id'], $email !== '' ? $email : null);
}

$token = csrf_token();
$base = base_url();

// 招待一覧
$invites = db()->query('SELECT * FROM invites ORDER BY created_at DESC LIMIT 100')->fetchAll();

$pageTitle = '招待コードの発行';
require __DIR__ . '/_app_header.php';
?>
<?php if ($newCode !== ''): ?>
    <div class="card">
        <p style="margin-top:0;">招待リンクを発行しました。これを相手に共有してください：</p>
        <input type="text" class="js-select" readonly value="<?= e($base . '/admin/signup.php?invite=' . $newCode) ?>">
    </div>
<?php endif; ?>

<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
    <label>招待先メール（任意・限定したい場合のみ）</label>
    <input type="email" name="email" placeholder="空欄なら誰でも使える招待">
    <p style="margin-top:16px;"><button type="submit" class="btn">招待コードを発行</button></p>
</form>

<div class="card">
    <div class="card__title">発行済み招待（最新100件）</div>
    <?php foreach ($invites as $iv): ?>
        <div style="border-bottom:1px solid var(--border); padding:8px 0; font-size:.86rem;">
            <code><?= e($iv['code']) ?></code>
            <?= $iv['used_by'] ? '— 使用済み' : ($iv['expires_at'] && $iv['expires_at'] < time() ? '— 期限切れ' : '— 未使用') ?>
            <?php if ($iv['email']): ?>（<?= e($iv['email']) ?>宛）<?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
