<?php

/**
 * 会員ディレクトリ（条件検索）。有効会員限定・ディレクトリ掲載ONの会員を検索する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();
$grouped = all_tags_grouped();

$filters = [
    'area'    => array_map('intval', (array) ($_GET['area'] ?? [])),
    'job'     => array_map('intval', (array) ($_GET['job'] ?? [])),
    'purpose' => array_map('intval', (array) ($_GET['purpose'] ?? [])),
    'keyword' => (string) ($_GET['keyword'] ?? ''),
];
$hasQuery = $filters['area'] || $filters['job'] || $filters['purpose'] || trim($filters['keyword']) !== '';
$results = $hasQuery || isset($_GET['go']) ? search_directory($filters, $member['id']) : search_directory([], $member['id']);

$checkedArea = array_flip($filters['area']);
$checkedJob = array_flip($filters['job']);
$checkedPurpose = array_flip($filters['purpose']);

$pageTitle = '会員ディレクトリ';
$showLogout = true;
$wide = true;
require __DIR__ . '/_header.php';

$renderChecks = function (array $tags, string $name, array $checked) {
    foreach ($tags as $t) {
        $id = (int) $t['id'];
        echo '<label style="display:inline-block;margin:2px 8px 2px 0;font-weight:normal;">'
            . '<input type="checkbox" name="' . e($name) . '[]" value="' . $id . '"' . (isset($checked[$id]) ? ' checked' : '') . '> '
            . e($t['label']) . '</label>';
    }
};
?>
<h1>会員ディレクトリ</h1>
<p class="muted"><a href="/member/dashboard.php">← 会員トップ</a> ／ <a href="/member/recommend.php">あなたへのおすすめ</a></p>

<form method="get" class="card">
    <input type="hidden" name="go" value="1">
    <label>キーワード（名前・PR・自己紹介・会社名）</label>
    <input type="text" name="keyword" value="<?= e($filters['keyword']) ?>" placeholder="例: 販路 / 製造 / 東京">
    <details style="margin-top:10px;">
        <summary style="cursor:pointer;">条件で絞り込む（場所・仕事・目的）</summary>
        <p style="margin:8px 0 4px;"><strong>場所</strong></p>
        <div><?php $renderChecks($grouped['area'] ?? [], 'area', $checkedArea); ?></div>
        <p style="margin:8px 0 4px;"><strong>仕事ジャンル</strong></p>
        <div><?php $renderChecks($grouped['job'] ?? [], 'job', $checkedJob); ?></div>
        <p style="margin:8px 0 4px;"><strong>目的</strong></p>
        <div><?php $renderChecks($grouped['purpose'] ?? [], 'purpose', $checkedPurpose); ?></div>
    </details>
    <p style="margin-top:14px;">
        <button type="submit" class="btn">検索</button>
        <a class="btn btn--ghost" href="/member/directory.php">条件をクリア</a>
    </p>
</form>

<p class="muted"><?= count($results) ?> 件　<span style="font-size:.82rem;">（ポイントの高い会員が上位に表示されます）</span></p>

<?php if ($results === []): ?>
    <div class="card"><p style="margin:0;">条件に合う会員が見つかりませんでした。</p></div>
<?php else: ?>
    <?php foreach ($results as $r):
        $labels = member_tag_labels($r['member_id']);
        $hasApprovedPhoto = ($r['photo_status'] ?? '') === 'approved';
        $bal = (int) ($r['points_earned'] ?? member_points_earned($r['member_id'])); // 累計獲得（称号の基準・取得時に集計済み）
    ?>
        <div class="card">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <?php if ($hasApprovedPhoto): ?>
                    <img src="/member/photo.php?id=<?= e($r['member_id']) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:10px;flex:none;">
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;">
                        <a href="/member/member_view.php?id=<?= e($r['member_id']) ?>"><?= e($r['name_text'] !== '' ? $r['name_text'] : '会員') ?></a>
                        <?php if (($r['age_text'] ?? '') !== ''): ?><span class="muted" style="font-weight:normal;">（<?= e($r['age_text']) ?>）</span><?php endif; ?>
                    </div>
                    <div style="margin:3px 0;"><span class="badge badge--title"><?= e(points_title($bal)) ?></span> <span class="muted" style="font-size:.82rem;"><?= number_format($bal) ?> pt</span></div>
                    <?php if (($r['company_title'] ?? '') !== ''): ?><div class="muted"><?= e($r['company_title']) ?></div><?php endif; ?>
                    <?php if (($r['headline'] ?? '') !== ''): ?><div style="margin:4px 0;"><?= e($r['headline']) ?></div><?php endif; ?>
                    <div style="margin-top:6px;">
                        <?php foreach (['area', 'job', 'purpose', 'offer'] as $cat): ?>
                            <?php foreach ($labels[$cat] ?? [] as $lb): ?>
                                <span style="display:inline-block;background:#eef2ff;color:#3730a3;border-radius:10px;padding:1px 8px;font-size:.78rem;margin:2px 4px 2px 0;"><?= e($lb) ?></span>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
