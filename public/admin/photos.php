<?php

/**
 * 写真モデレーション：承認待ち一覧。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $id = (string) ($_POST['id'] ?? '');
    $decision = (string) ($_POST['action'] ?? '');
    if ($id !== '' && in_array($decision, ['approved', 'rejected'], true)) {
        admin_moderate_photo($id, $decision);
        $msg = $decision === 'approved' ? '承認しました。' : '却下しました。';
    }
}

$pending = pending_photo_members();
$token = csrf_token();
$pageTitle = '写真承認';
$pageSub = count($pending) . ' 件が承認待ち';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash flash--ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($pending === []): ?>
    <div class="card"><p style="margin:0;">承認待ちの写真はありません。</p></div>
<?php else: foreach ($pending as $row): ?>
    <div class="card" style="display:flex;gap:14px;align-items:center;">
        <img src="member_photo.php?id=<?= e($row['member_id']) ?>" alt="" style="width:90px;height:90px;object-fit:cover;border-radius:10px;">
        <div style="flex:1;">
            <div><code><?= e($row['login_id']) ?></code> <?= e($row['display_name'] ?? '') ?></div>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($row['member_id']) ?>">
                <button class="btn" name="action" value="approved">承認</button>
            </form>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($row['member_id']) ?>">
                <button class="btn btn--ghost" name="action" value="rejected">却下</button>
            </form>
            <a href="member_detail.php?id=<?= e($row['member_id']) ?>" style="margin-left:8px;">詳細</a>
        </div>
    </div>
<?php endforeach; endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
