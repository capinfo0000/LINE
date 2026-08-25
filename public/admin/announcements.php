<?php

/**
 * 「さがす」上部のお知らせ（スライド）の管理。
 * 追加・編集・削除・並べ替え・公開/非公開をここで行う。
 * 会員側は公開中のものだけを、並び順に自動送りで表示する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = (string) ($_GET['msg'] ?? '');
$msgType = ((string) ($_GET['type'] ?? 'ok')) === 'ng' ? 'ng' : 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $ok = announcement_delete($id);
        $note = $ok ? 'お知らせを削除しました。' : '対象のお知らせが見つかりませんでした。';
        $type = $ok ? 'ok' : 'ng';
    } else {
        $r = announcement_save($id, $_POST);
        $note = $r['message'];
        $type = $r['ok'] ? 'ok' : 'ng';
    }
    header('Location: /admin/announcements.php?msg=' . rawurlencode($note) . '&type=' . $type);
    exit;
}

$rows = all_announcements();
$themes = announcement_themes();
$token = csrf_token();
$pageTitle = 'お知らせ';
$pageSub = '「さがす」上部に出るスライド（' . count($rows) . '件）';
require __DIR__ . '/_app_header.php';

/** 1件分の入力欄を出す（新規追加と編集で同じ形を使う）。 */
$renderForm = static function (array $a, array $themes, string $token, bool $isNew): void {
    $id = (int) ($a['id'] ?? 0);
    ?>
    <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="card__title">
            <?= $isNew ? 'お知らせを追加' : 'お知らせ #' . $id ?>
            <?php if (!$isNew): ?>
                <span class="badge badge--<?= (int) $a['is_active'] === 1 ? 'info' : 'mute' ?>"><?= (int) $a['is_active'] === 1 ? '公開中' : '非公開' ?></span>
            <?php endif; ?>
        </div>

        <label>小見出し（任意・上に小さく出る文字）</label>
        <input type="text" name="label" maxlength="20" value="<?= e((string) ($a['label'] ?? '')) ?>" placeholder="例: 紹介キャンペーン">

        <label>見出し（必須・大きく出る文言）</label>
        <input type="text" name="title" maxlength="40" required value="<?= e((string) ($a['title'] ?? '')) ?>" placeholder="例: 5人ご紹介で、翌月無料。">

        <label>説明（任意・下に一行）</label>
        <input type="text" name="subtitle" maxlength="60" value="<?= e((string) ($a['subtitle'] ?? '')) ?>" placeholder="例: 紹介した仲間が続くほどおトクに">

        <label>タップ先（任意・空ならリンクなし）</label>
        <input type="text" name="url" maxlength="300" value="<?= e((string) ($a['url'] ?? '')) ?>" placeholder="例: /member/points.php">
        <p class="hint" style="margin:4px 0 0;">サイト内は「/member/points.php」のように / から始めます。外部は https:// から始まるURLのみ。</p>

        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-top:10px;">
            <div>
                <label style="margin-top:0;">配色</label>
                <select name="theme">
                    <?php foreach ($themes as $k => $lb): ?>
                        <option value="<?= e($k) ?>"<?= ((string) ($a['theme'] ?? 'brand') === $k) ? ' selected' : '' ?>><?= e($lb) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="margin-top:0;">並び順</label>
                <input type="number" name="sort_order" value="<?= (int) ($a['sort_order'] ?? 0) ?>" style="max-width:90px;">
            </div>
            <label style="font-weight:normal;display:inline-flex;gap:6px;align-items:center;margin:0 0 8px;">
                <input type="checkbox" name="is_active" value="1" style="width:auto;"<?= (int) ($a['is_active'] ?? 1) === 1 ? ' checked' : '' ?>>
                会員に公開する
            </label>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
            <button type="submit" class="btn"><?= $isNew ? '追加する' : '保存する' ?></button>
            <?php if (!$isNew): ?>
                <button type="submit" class="btn btn--danger" name="action" value="delete"
                        data-confirm="このお知らせを削除します。元に戻せません。よろしいですか？">削除</button>
            <?php endif; ?>
        </div>
    </form>
    <?php
};
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
    <div class="card__title">表示のしかた</div>
    <p class="hint" style="margin:0;">
        公開中のお知らせが「さがす」の上部に横並びで出て、<strong>約6秒ごとに自動で次へ切り替わります</strong>（指で左右に送ることもできます）。<br>
        並び順の数字が小さいものから表示します。非公開にすると会員側から消えますが、内容は残ります。全部を非公開にすると枠ごと出ません。<br>
        <a href="/member/directory.php" target="_blank" rel="noopener">会員側の画面を開く →</a>
    </p>
</div>

<?php foreach ($rows as $a): ?>
    <?php $renderForm($a, $themes, $token, false); ?>
<?php endforeach; ?>

<?php if (count($rows) < 10): ?>
    <?php $renderForm(['theme' => 'brand', 'sort_order' => count($rows), 'is_active' => 1], $themes, $token, true); ?>
<?php else: ?>
    <div class="card"><p class="hint" style="margin:0;">お知らせは10件までです。追加するには、不要なものを削除してください。</p></div>
<?php endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
