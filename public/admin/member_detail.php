<?php

/**
 * 会員詳細・操作（承認・入金状況・ID/PW再発行・写真承認・ステータス変更）。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$id = (string) ($_GET['id'] ?? ($_POST['id'] ?? ''));
$member = $id !== '' ? find_member_by_id($id) : null;
if ($member === null) {
    http_response_code(404);
    exit('会員が見つかりません。');
}
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    switch ($action) {
        case 'reissue':
            $msg = admin_reissue_credentials($id) ? 'ID/PWを再発行し配布しました。' : 'ID/PWを再発行しました（配布経路が無いため送信はスキップ）。';
            break;
        case 'status':
            admin_set_member_status($id, (string) ($_POST['status'] ?? ''));
            $msg = 'ステータスを変更しました。';
            break;
        case 'photo_approve':
            admin_moderate_photo($id, 'approved');
            $msg = '写真を承認しました。';
            break;
        case 'photo_reject':
            admin_moderate_photo($id, 'rejected');
            $msg = '写真を却下しました。';
            break;
    }
    $member = find_member_by_id($id);
}

$profile = get_profile($id);
$payments = member_payments($id);
$token = csrf_token();
$pageTitle = '会員詳細';
$pageSub = $member['login_id'];
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash flash--ok"><?= e($msg) ?></div><?php endif; ?>
<p><a href="members.php">← 会員一覧</a></p>

<div class="card">
    <div class="card__title">基本情報</div>
    <p>ログインID：<code><?= e($member['login_id']) ?></code>　ステータス：<strong><?= e($member['status']) ?></strong>
       <?= (int) $member['must_change_pw'] === 1 ? '<span class="muted">(要PW変更)</span>' : '' ?></p>
    <p>名前：<?= e($profile['name_text'] ?: ($member['display_name'] ?? '-')) ?>　メール：<?= e($member['email'] ?? '-') ?></p>
    <p>LINE：<?= e($member['line_user_id'] ?? '-') ?>　入会日：<?= $member['joined_at'] ? e(date('Y-m-d', (int) $member['joined_at'])) : '-' ?></p>
</div>

<div class="card">
    <div class="card__title">入金状況</div>
    <?php if ($payments === []): ?>
        <p class="muted">入金記録はありません。</p>
    <?php else: foreach ($payments as $p): ?>
        <p><?= e(format_amount((int) $p['amount'], (string) $p['currency'])) ?>　<?= e($p['status']) ?>
           <?= $p['paid_at'] ? e(date('Y-m-d H:i', (int) $p['paid_at'])) : '' ?>
           <span class="muted"><?= e($p['stripe_checkout_session_id']) ?></span></p>
    <?php endforeach; endif; ?>
</div>

<?php if (($profile['photo_status'] ?? 'none') !== 'none'): ?>
<div class="card">
    <div class="card__title">顔写真（状態：<?= e($profile['photo_status']) ?>）</div>
    <?php if ($profile['photo_status'] === 'pending' || $profile['photo_status'] === 'approved'): ?>
        <p><img src="member_photo.php?id=<?= e($id) ?>" alt="" style="max-width:160px;border-radius:10px;"></p>
    <?php endif; ?>
    <?php if ($profile['photo_status'] === 'pending'): ?>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
            <button class="btn" name="action" value="photo_approve">承認</button>
        </form>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
            <button class="btn btn--ghost" name="action" value="photo_reject">却下</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card__title">操作</div>
    <form method="post" style="margin-bottom:12px;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
        <button class="btn" name="action" value="reissue" onclick="return confirm('ID/PWを再発行して配布します。よろしいですか？');">ID/PWを再発行して配布</button>
    </form>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
        <label>ステータス変更</label>
        <select name="status">
            <?php foreach (['active' => '有効', 'suspended' => '停止', 'cancelled' => '退会', 'pending_payment' => '未入金'] as $v => $l): ?>
                <option value="<?= e($v) ?>"<?= $member['status'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--ghost" name="action" value="status">変更</button>
    </form>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
