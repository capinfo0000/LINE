<?php

/**
 * タグ管理：カテゴリ別のタグを追加・有効/無効切替する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        admin_add_tag((string) ($_POST['category_key'] ?? ''), (string) ($_POST['label'] ?? ''));
        $msg = 'タグを追加しました。';
    } elseif ($action === 'toggle') {
        admin_set_tag_active((int) ($_POST['tag_id'] ?? 0), (int) ($_POST['active'] ?? 0) === 1);
        $msg = 'タグの状態を変更しました。';
    }
}

$cats = get_tag_categories();
$rows = db()->query('SELECT * FROM tags ORDER BY category_key, sort, label')->fetchAll();
$byCat = [];
foreach ($rows as $r) {
    $byCat[$r['category_key']][] = $r;
}
$token = csrf_token();
$pageTitle = 'タグ管理';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash flash--ok"><?= e($msg) ?></div><?php endif; ?>

<form method="post" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add">
    <div><label>カテゴリ</label>
        <select name="category_key">
            <?php foreach ($cats as $c): ?><option value="<?= e($c['key']) ?>"><?= e($c['label']) ?></option><?php endforeach; ?>
        </select></div>
    <div style="flex:1;min-width:160px;"><label>タグ名</label><input type="text" name="label"></div>
    <div><button type="submit" class="btn">追加</button></div>
</form>

<?php foreach ($cats as $c): ?>
    <div class="card">
        <div class="card__title"><?= e($c['label']) ?></div>
        <?php foreach ($byCat[$c['key']] ?? [] as $t): ?>
            <span style="display:inline-block;margin:3px;padding:2px 8px;border:1px solid var(--border);border-radius:10px;<?= (int) $t['is_active'] === 0 ? 'opacity:.5;' : '' ?>">
                <?= e($t['label']) ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="tag_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="active" value="<?= (int) $t['is_active'] === 1 ? 0 : 1 ?>">
                    <button style="border:none;background:none;cursor:pointer;color:#2563eb;font-size:.78rem;"><?= (int) $t['is_active'] === 1 ? '無効化' : '有効化' ?></button>
                </form>
            </span>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
