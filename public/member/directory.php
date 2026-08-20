<?php

/**
 * 会員ディレクトリ（条件検索）。有効会員限定・ディレクトリ掲載ONの会員を検索する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();

// カードの♡「気になる」トグル（POST）。処理後はPRGで元の検索条件のURLへ戻す。
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $likeTo = (string) ($_POST['like_to'] ?? '');
    if ($likeTo !== '') {
        toggle_interest((string) $member['id'], $likeTo);
    }
    $ret = (string) ($_POST['return'] ?? '/member/directory.php');
    if (!preg_match('#^/member/[A-Za-z0-9_./?=&%-]*$#', $ret)) {
        $ret = '/member/directory.php'; // オープンリダイレクト対策
    }
    header('Location: ' . $ret);
    exit;
}

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
/** 会員カード1枚を出力（さがすグリッド／おすすめカルーセルで共通利用）。 */
$renderCard = function (string $mid, string $nm, string $age, bool $hasPhoto) use ($member, $rankClass): void {
    $labels = member_tag_labels($mid);
    $title = points_title(member_points_earned($mid));
    $nm = $nm !== '' ? $nm : '会員';
    $ini = mb_substr($nm, 0, 1);
    $hue = crc32($mid) % 360;
    $hue2 = ($hue + 38) % 360;
    $cardBg = $hasPhoto ? '' : ' style="background:linear-gradient(150deg,hsl(' . $hue . ' 66% 54%),hsl(' . $hue2 . ' 64% 45%))"';
    $area = $labels['area'][0] ?? '';
    $job = $labels['job'][0] ?? '';
    $want = $labels['purpose'][0] ?? '';
    $liked = has_interest((string) $member['id'], $mid);
    ?>
    <div class="tp-card"<?= $cardBg ?>>
        <?php if ($hasPhoto): ?><img src="/member/photo.php?id=<?= e($mid) ?>" alt=""><?php else: ?><span class="tp-ini"><?= e($ini) ?></span><?php endif; ?>
        <a class="tp-cardlink" href="/member/member_view.php?id=<?= e($mid) ?>" aria-label="<?= e($nm) ?> のプロフィール"></a>
        <span class="tp-crank <?= $rankClass($title) ?>"><?= e($title) ?></span>
        <div class="tp-cinfo">
            <?php if ($age !== '' || $area !== ''): ?><div class="aa"><?= $age !== '' ? e($age) . '歳' : '' ?><?= ($age !== '' && $area !== '') ? '・' : '' ?><?= e($area) ?></div><?php endif; ?>
            <div class="nm"><?= e($nm) ?></div>
            <div class="tp-ptags">
                <?php if ($job !== ''): ?><span class="tp-ptag tp-ptag--on"><?= e($job) ?></span><?php endif; ?>
                <?php if ($want !== ''): ?><span class="tp-ptag">求む・<?= e($want) ?></span><?php endif; ?>
            </div>
        </div>
        <button class="tp-clike<?= $liked ? ' on' : '' ?>" form="likeform" type="submit" name="like_to" value="<?= e($mid) ?>" aria-label="気になる">
            <svg viewBox="0 0 24 24" fill="<?= $liked ? '#fff' : 'none' ?>" stroke="#f96d6d" stroke-width="2"><path d="M12 21s-7-4.4-9.3-8.6C1 9 2.6 5.5 6 5.5c2 0 3.2 1.1 4 2.2.8-1.1 2-2.2 4-2.2 3.4 0 5 3.5 3.3 6.9C19 16.6 12 21 12 21z"/></svg>
        </button>
    </div>
    <?php
};
// 検索していないときだけ「本日のおすすめ」（双方向マッチ）を出す。
$recs = !$hasQuery ? compute_recommendations_for((string) $member['id'], 10) : [];
?>
<h1 style="margin:0 0 12px;font-size:1.5rem;">さがす</h1>

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

<form id="likeform" method="post" hidden>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '/member/directory.php') ?>">
</form>

<?php if (!$hasQuery): ?>
    <div class="tp-pickup"><b>本日のおすすめ</b><span>あなたの条件に合う会員をピックアップ</span></div>
    <?php if ($recs !== []): ?>
        <div class="tp-secttl">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 15 8.5 22 9.3l-5 4.6 1.3 6.9L12 17.8 5.7 20.8 7 13.9 2 9.3l7-.8z"/></svg>
            本日のおすすめ
            <a class="more" href="/member/recommend.php">すべて見る →</a>
        </div>
        <div class="tp-rail">
            <?php foreach ($recs as $rc): $renderCard((string) $rc['member_id'], (string) ($rc['name'] ?? ''), (string) ($rc['age_text'] ?? ''), ($rc['photo_status'] ?? '') === 'approved'); endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="tp-secttl">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 3-5.5 6.5-5.5S15.5 16.4 15.5 20"/><circle cx="17" cy="8.5" r="3"/><path d="M17 14.5c3.2 0 5 1.9 5 5.5"/></svg>
        すべての会員 <span class="more" style="color:var(--faint);font-weight:700;"><?= count($results) ?>名</span>
    </div>
<?php else: ?>
    <p class="tp-count"><b><?= count($results) ?></b> 名がヒット <span style="color:var(--faint);font-size:.8rem;">・ポイントの高い方が上位</span></p>
<?php endif; ?>

<?php if ($results === []): ?>
    <div class="card"><p style="margin:0;">条件に合う会員が見つかりませんでした。条件を変えてお試しください。</p></div>
<?php else: ?>
    <div class="tp-grid">
        <?php foreach ($results as $r): $renderCard((string) $r['member_id'], (string) ($r['name_text'] ?? ''), (string) ($r['age_text'] ?? ''), ($r['photo_status'] ?? '') === 'approved'); endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
