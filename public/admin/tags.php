<?php

/**
 * タグ管理：カテゴリ別のタグを作成・削除する。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $r = admin_add_tag((string) ($_POST['category_key'] ?? ''), (string) ($_POST['label'] ?? ''));
        $msg = $r['message'];
        $msgType = $r['ok'] ? 'ok' : 'ng';
    } elseif ($action === 'delete') {
        $ok = admin_delete_tag((int) ($_POST['tag_id'] ?? 0));
        $msg = $ok ? 'タグを削除しました。' : 'タグが見つかりませんでした。';
        $msgType = $ok ? 'ok' : 'ng';
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
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<form method="post" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add">
    <div><label>カテゴリ</label>
        <select name="category_key">
            <?php foreach ($cats as $c): ?><option value="<?= e($c['key']) ?>"><?= e($c['label']) ?></option><?php endforeach; ?>
        </select></div>
    <div style="flex:1;min-width:160px;"><label>タグ名</label><input type="text" name="label"></div>
    <div><button type="submit" class="btn">作成</button></div>
</form>

<?php foreach ($cats as $c): ?>
    <div class="card">
        <div class="card__title"><?= e($c['label']) ?></div>
        <?php foreach ($byCat[$c['key']] ?? [] as $t): ?>
            <span style="display:inline-block;margin:3px;padding:2px 8px;border:1px solid var(--border);border-radius:10px;">
                <?= e($t['label']) ?>
                <form method="post" style="display:inline;" data-confirm="タグ「<?= e($t['label']) ?>」を削除します。このタグを設定している会員からも外れます（元に戻せません）。よろしいですか？">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tag_id" value="<?= (int) $t['id'] ?>">
                    <button style="border:none;background:none;cursor:pointer;color:var(--dng);font-size:.78rem;">削除</button>
                </form>
            </span>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
