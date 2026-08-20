<?php

/**
 * 会員ディレクトリ（条件検索）。有効会員限定・ディレクトリ掲載ONの会員を検索する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();
$grouped = all_tags_grouped();

// プランによる検索絞り込みの制限（ベーシックは地域のみ／無料フェーズ・プレミアムは全条件）。
$canFullSearch = plan_can($member, 'search_full');

$filters = [
    'area'    => array_map('intval', (array) ($_GET['area'] ?? [])),
    'job'     => array_map('intval', (array) ($_GET['job'] ?? [])),
    'purpose' => array_map('intval', (array) ($_GET['purpose'] ?? [])),
    'keyword' => (string) ($_GET['keyword'] ?? ''),
];
// ベーシックは地域以外の条件を無効化する。
if (!$canFullSearch) {
    $filters['job'] = [];
    $filters['purpose'] = [];
    $filters['keyword'] = '';
}
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
<?php
$rankClass = static function (string $t): string {
    return ['プラチナ' => 'rank--plat', 'ゴールド' => 'rank--gold', 'レギュラー' => 'rank--reg', 'ルーキー' => 'rank--rookie'][$t] ?? 'rank--rookie';
};
?>
<div style="display:flex;align-items:center;gap:10px;margin:0 0 12px;">
    <h1 style="margin:0;font-size:1.5rem;">さがす</h1>
    <a class="muted" style="margin-left:auto;font-size:.85rem;" href="/member/recommend.php">あなたへのおすすめ →</a>
</div>

<form method="get">
    <input type="hidden" name="go" value="1">
    <?php if ($canFullSearch): ?>
        <div class="tp-search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" name="keyword" value="<?= e($filters['keyword']) ?>" placeholder="名前・会社・キーワードで探す">
        </div>
        <details style="margin-top:10px;">
            <summary class="tp-fchip" style="width:max-content;list-style:none;">🔎 エリア・業種・目的で絞り込む</summary>
            <div style="margin-top:12px;">
                <p style="margin:8px 0 4px;font-weight:700;font-size:.85rem;">場所</p>
                <div><?php $renderChecks($grouped['area'] ?? [], 'area', $checkedArea); ?></div>
                <p style="margin:12px 0 4px;font-weight:700;font-size:.85rem;">仕事ジャンル</p>
                <div><?php $renderChecks($grouped['job'] ?? [], 'job', $checkedJob); ?></div>
                <p style="margin:12px 0 4px;font-weight:700;font-size:.85rem;">目的</p>
                <div><?php $renderChecks($grouped['purpose'] ?? [], 'purpose', $checkedPurpose); ?></div>
            </div>
        </details>
    <?php else: ?>
        <p style="margin:2px 0 6px;font-weight:700;font-size:.9rem;">エリアで絞り込む</p>
        <div><?php $renderChecks($grouped['area'] ?? [], 'area', $checkedArea); ?></div>
        <div class="flash" style="background:#f8fafc;border:1px solid var(--border);margin-top:12px;">
            仕事ジャンル・目的・キーワード検索は <strong>プレミアム</strong>限定です。
            <a href="/member/billing.php">プレミアムにする</a>
        </div>
    <?php endif; ?>
    <p style="margin:12px 0;">
        <button type="submit" class="btn">検索</button>
        <a class="btn btn--ghost" href="/member/directory.php">クリア</a>
    </p>
</form>

<p class="tp-count"><b><?= count($results) ?></b> 名がヒット <span style="color:var(--faint);font-size:.8rem;">・ポイントの高い方が上位</span></p>

<?php if ($results === []): ?>
    <div class="card"><p style="margin:0;">条件に合う会員が見つかりませんでした。条件を変えてお試しください。</p></div>
<?php else: ?>
    <div class="tp-grid">
    <?php foreach ($results as $r):
        $labels = member_tag_labels($r['member_id']);
        $hasPhoto = ($r['photo_status'] ?? '') === 'approved';
        $bal = (int) ($r['points_earned'] ?? member_points_earned($r['member_id']));
        $title = points_title($bal);
        $nm = ($r['name_text'] ?? '') !== '' ? $r['name_text'] : '会員';
        $ini = mb_substr($nm, 0, 1);
        $hue = crc32((string) $r['member_id']) % 360;
        $hue2 = ($hue + 38) % 360;
        $phStyle = $hasPhoto ? '' : ' style="background:linear-gradient(150deg,hsl(' . $hue . ' 68% 56%),hsl(' . $hue2 . ' 66% 47%))"';
        $area = $labels['area'][0] ?? '';
        $job = $labels['job'][0] ?? '';
        $want = $labels['purpose'][0] ?? '';
    ?>
        <a class="tp-card" href="/member/member_view.php?id=<?= e($r['member_id']) ?>">
            <div class="tp-ph"<?= $phStyle ?>>
                <?php if ($hasPhoto): ?>
                    <img src="/member/photo.php?id=<?= e($r['member_id']) ?>" alt="">
                <?php else: ?>
                    <span class="tp-ini"><?= e($ini) ?></span>
                <?php endif; ?>
                <?php if ($area !== ''): ?><span class="tp-area">📍<?= e($area) ?></span><?php endif; ?>
                <span class="tp-nm"><b><?= e($nm) ?></b><?php if (($r['age_text'] ?? '') !== ''): ?><span><?= e($r['age_text']) ?></span><?php endif; ?></span>
            </div>
            <div class="tp-cmeta">
                <div class="tp-rankrow"><span class="rank <?= $rankClass($title) ?>"><?= e($title) ?></span><span class="tp-pts"><?= number_format($bal) ?>pt</span></div>
                <div class="tp-tags">
                    <?php if ($job !== ''): ?><span class="tp-tag"><?= e($job) ?></span><?php endif; ?>
                    <?php if ($want !== ''): ?><span class="tp-tag tp-tag--want">求む・<?= e($want) ?></span><?php endif; ?>
                </div>
            </div>
        </a>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
