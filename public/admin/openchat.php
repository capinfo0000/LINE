<?php

/**
 * オープンチャットURL管理：入金後に Bot が配信する招待URLを登録・管理する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $url = trim((string) ($_POST['url'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? 'オープンチャット'));
        if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $msg = 'URLの形式が正しくありません。';
            $msgType = 'ng';
        } else {
            $gid = 'grp_' . bin2hex(random_bytes(5));
            $stmt = db()->prepare("INSERT INTO groups (id, name, kind, invite_url, is_active, created_at) VALUES (?,?,'openchat',?,1,?)");
            $stmt->execute([$gid, mb_substr($name, 0, 100), mb_substr($url, 0, 500), time()]);
            $msg = 'オープンチャットURLを登録しました。';
        }
    } elseif ($action === 'toggle') {
        $gid = (string) ($_POST['id'] ?? '');
        $active = (int) ($_POST['active'] ?? 0) === 1 ? 1 : 0;
        $stmt = db()->prepare('UPDATE groups SET is_active = ? WHERE id = ?');
        $stmt->execute([$active, $gid]);
        if ($stmt->rowCount() > 0) {
            $msg = $active === 1 ? '有効にしました。' : '無効にしました。';
        } else {
            $msg = '対象が見つかりませんでした。画面を開き直してもう一度お試しください。';
            $msgType = 'ng';
        }
    }
}

$groups = db()->query("SELECT * FROM groups WHERE kind='openchat' ORDER BY created_at DESC")->fetchAll();
$active = active_openchat_url();
$token = csrf_token();
$pageTitle = 'オープンチャット管理';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>
<div class="card">
    <p>入金後に Bot が配信する招待URLです。<strong>最新の有効なURL</strong>が配信に使われます。</p>
    <p class="muted">現在配信に使われるURL：<?= $active !== null ? e($active) : '（未登録）' ?></p>
</div>

<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add">
    <label>名称</label><input type="text" name="name" placeholder="オープンチャット">
    <label>招待URL</label><input type="url" name="url" placeholder="https://line.me/ti/g2/...">
    <p style="margin-top:12px;"><button type="submit" class="btn">登録</button></p>
</form>

<div class="card">
    <div class="card__title">登録済み</div>
    <?php foreach ($groups as $g): ?>
        <div style="border-bottom:1px solid var(--border);padding:8px 0;">
            <strong><?= e($g['name']) ?></strong> <?= (int) $g['is_active'] === 1 ? '<span class="muted">有効</span>' : '<span class="muted">無効</span>' ?><br>
            <span class="muted" style="font-size:.84rem;word-break:break-all;"><?= e($g['invite_url']) ?></span>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= e($g['id']) ?>"><input type="hidden" name="active" value="<?= (int) $g['is_active'] === 1 ? 0 : 1 ?>">
                <button class="btn btn--ghost" style="padding:2px 8px;"><?= (int) $g['is_active'] === 1 ? '無効化' : '有効化' ?></button>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if ($groups === []): ?><p class="muted">未登録です。</p><?php endif; ?>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
