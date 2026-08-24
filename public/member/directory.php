<?php

/**
 * 会員ディレクトリ（条件検索）。有効会員限定・ディレクトリ掲載ONの会員を検索する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();

// 自己紹介を公式LINEに送るまで「さがす」は見せない。
// ※ 黙って自己紹介ページへ飛ばすと分かりにくいので、「さがす」画面のままロック案内を表示する。
if (member_needs_intro($member)) {
    $pageTitle = 'さがす';
    $showLogout = true;
    $wide = true;
    $appWide = true;
    $hideBrand = true;
    require __DIR__ . '/_header.php';
    ?>
    <h1 style="margin:0 0 12px;font-size:1.5rem;">さがす</h1>
    <div class="card" style="text-align:center;">
        <div style="font-size:2.4rem;line-height:1;margin-bottom:8px;">🔒</div>
        <div class="card__title" style="justify-content:center;">まだ「さがす」は使えません</div>
        <p class="muted" style="margin:0 auto 16px;max-width:22em;">
            会員どうしの検索・閲覧を始めるには、まず<strong>公式LINEのトークに自己紹介を送信</strong>してください。
            送信が確認されると自動で解除されます。
        </p>
        <p style="margin:0;"><a class="btn btn--lg" href="/member/intro.php?gate=1">自己紹介を送る（ひな形を開く）</a></p>
    </div>
    <?php
    require __DIR__ . '/_footer.php';
    exit;
}

// カードの♡「気になる」トグル（POST）。処理後はPRGで元の検索条件のURLへ戻す。
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $likeTo = (string) ($_POST['like_to'] ?? '');
    if ($likeTo !== '') {
        toggle_interest((string) $member['id'], $likeTo);
    }
    $ret = (string) ($_POST['return'] ?? '/member/directory.php');
    if (!preg_match('#^/member/[A-Za-z0-9_./?=&%+-]*$#', $ret)) {
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

// カテゴリタブ（pato風）。検索条件が指定されているときは検索結果を優先する。
$tabs = [
    'osusume' => 'おすすめ',
    'kininaru' => '気になる',
    'footprint' => '足あと',
    'new' => '新着',
    'ranking' => 'ランキング',
];
$tab = (string) ($_GET['tab'] ?? 'osusume');
if (!isset($tabs[$tab])) {
    $tab = 'osusume';
}
$isRanking = false;
if ($hasQuery || isset($_GET['go'])) {
    // キーワード/絞り込み検索：実績上位順。
    $results = search_directory($filters, $member['id']);
} elseif ($tab === 'kininaru') {
    // 自分が「気になる」した相手のみ（♡を付けた新しい順）。
    // 取得は対象ID全件（上限は十分大きく）→ liked の並び順で並べ直す。
    $likedIds = liked_member_ids($member['id']);
    $results = search_directory([], $member['id'], max(200, count($likedIds)), 'points', $likedIds);
    if ($results !== [] && $likedIds !== []) {
        $order = array_flip($likedIds);
        usort($results, static fn ($a, $b) => ($order[(string) $a['member_id']] ?? PHP_INT_MAX) <=> ($order[(string) $b['member_id']] ?? PHP_INT_MAX));
    }
} elseif ($tab === 'footprint') {
    // 自分のプロフィールを見に来た相手（足あと・新しい順）。
    $visitorIds = footprint_visitor_ids($member['id']);
    $results = search_directory([], $member['id'], max(200, count($visitorIds)), 'points', $visitorIds);
    // 足あとの新しい順を維持（search_directory はポイント順で返すため並べ直す）。
    if ($results !== [] && $visitorIds !== []) {
        $order = array_flip($visitorIds);
        usort($results, static fn ($a, $b) => ($order[(string) $a['member_id']] ?? PHP_INT_MAX) <=> ($order[(string) $b['member_id']] ?? PHP_INT_MAX));
    }
} elseif ($tab === 'new') {
    $results = search_directory([], $member['id'], 60, 'new');
} elseif ($tab === 'ranking') {
    $results = search_directory([], $member['id'], 60, 'points');
    $isRanking = true;
} else {
    $results = search_directory([], $member['id']);
}

$checkedArea = array_flip($filters['area']);
$checkedJob = array_flip($filters['job']);
$checkedPurpose = array_flip($filters['purpose']);

$pageTitle = 'さがす';
$showLogout = true;
$wide = true;
$appWide = true;
$hideBrand = true;
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
/** 会員カード1枚を出力（さがすグリッド／おすすめカルーセルで共通利用）。$rank>0 で順位バッジ表示。 */
$renderCard = function (string $mid, string $nm, string $age, bool $hasPhoto, int $rank = 0) use ($member, $rankClass): void {
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
        <?php if ($rank > 0): ?><span class="tp-rankno<?= $rank <= 3 ? ' top' : '' ?>"><?= $rank ?></span><?php endif; ?>
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
// 「おすすめ」タブ（検索なし）でだけ「あなたへのおすすめ」（双方向マッチ）を横スクロールで出す。
// マッチが無ければ全会員の上位をフォールバック表示して、常にカルーセルが出るようにする。
$showRail = !$hasQuery && $tab === 'osusume';
$recs = $showRail ? compute_recommendations_for((string) $member['id'], 10) : [];
$recCards = [];
if ($showRail) {
    if ($recs !== []) {
        foreach ($recs as $rc) {
            $recCards[] = [(string) $rc['member_id'], (string) ($rc['name'] ?? ''), (string) ($rc['age_text'] ?? ''), ($rc['photo_status'] ?? '') === 'approved'];
        }
    } else {
        foreach (array_slice($results, 0, 10) as $r) {
            $recCards[] = [(string) $r['member_id'], (string) ($r['name_text'] ?? ''), (string) ($r['age_text'] ?? ''), ($r['photo_status'] ?? '') === 'approved'];
        }
    }
}
?>
<form id="likeform" method="post" hidden>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '/member/directory.php') ?>">
</form>

<!-- 検索バー＋絞り込みドロップダウン（pato風トップバー） -->
<form method="get" class="tp-topbar">
    <input type="hidden" name="go" value="1">
    <div class="tp-search">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text" name="keyword" value="<?= e($filters['keyword']) ?>" placeholder="<?= $canFullSearch ? '名前・会社・キーワードで探す' : 'エリアで絞り込む（下のアイコン）' ?>"<?= $canFullSearch ? '' : ' readonly' ?>>
    </div>
    <details class="tp-filterbox"<?= $hasQuery ? ' open' : '' ?>>
        <summary class="tp-filtericon" aria-label="絞り込み">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18M6 12h12M10 19h4"/></svg>
        </summary>
        <div class="tp-filterpanel">
            <p style="margin:2px 0 4px;font-weight:700;font-size:.85rem;">場所</p>
            <div><?php $renderChecks($grouped['area'] ?? [], 'area', $checkedArea); ?></div>
            <?php if ($canFullSearch): ?>
                <p style="margin:12px 0 4px;font-weight:700;font-size:.85rem;">仕事ジャンル</p>
                <div><?php $renderChecks($grouped['job'] ?? [], 'job', $checkedJob); ?></div>
                <p style="margin:12px 0 4px;font-weight:700;font-size:.85rem;">目的</p>
                <div><?php $renderChecks($grouped['purpose'] ?? [], 'purpose', $checkedPurpose); ?></div>
            <?php else: ?>
                <p class="muted" style="font-size:.8rem;margin:10px 0 0;">仕事ジャンル・目的・キーワード検索は<strong>プレミアム</strong>限定です。<a href="/member/billing.php">プレミアムにする</a></p>
            <?php endif; ?>
            <div class="tp-filteractions">
                <button type="submit" class="btn">この条件で検索</button>
                <a class="btn btn--ghost" href="/member/directory.php">クリア</a>
            </div>
        </div>
    </details>
</form>

<!-- カテゴリタブ（横スクロール） -->
<nav class="tp-cat" aria-label="カテゴリ">
    <?php foreach ($tabs as $k => $label): ?>
        <a href="/member/directory.php?tab=<?= e($k) ?>" class="tp-cattab<?= (!$hasQuery && $tab === $k) ? ' on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$hasQuery && $tab === 'osusume'): ?>
    <!-- ヒーローカルーセル（スワイプ式・CSPセーフ） -->
    <div class="tp-promo" role="region" aria-label="お知らせ">
        <a class="tp-pslide tp-pslide--brand" href="/member/recommend.php">
            <span class="tp-slabel">ENLINK</span>
            <b>ビジネスの縁が、ここから。</b>
            <span class="tp-ssub">相性で出会う、次のパートナー</span>
        </a>
        <a class="tp-pslide tp-pslide--ref" href="/member/points.php">
            <span class="tp-slabel">紹介キャンペーン</span>
            <b>5人ご紹介で、翌月無料。</b>
            <span class="tp-ssub">紹介した仲間が続くほどおトクに</span>
        </a>
        <a class="tp-pslide tp-pslide--rank" href="/member/directory.php?tab=ranking">
            <span class="tp-slabel">RANKING</span>
            <b>実績ポイント・ランキング</b>
            <span class="tp-ssub">活躍している会員をチェック</span>
        </a>
    </div>
    <div class="tp-dots"><span></span><span></span><span></span></div>

    <!-- キャンペーンバー -->
    <a class="tp-coupon" href="/member/points.php">
        <span class="tp-cbadge">紹介</span>
        <span class="tp-ctext"><b>5人ご紹介で翌月の月額が0円</b>／紹介はずっと有効</span>
        <span class="tp-chelp" aria-hidden="true">?</span>
    </a>
<?php endif; ?>

<?php if ($showRail && $recCards !== []): ?>
    <div class="tp-secttl">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 15 8.5 22 9.3l-5 4.6 1.3 6.9L12 17.8 5.7 20.8 7 13.9 2 9.3l7-.8z"/></svg>
        あなたへのおすすめ
        <a class="more" href="/member/recommend.php">すべて見る →</a>
    </div>
    <div class="tp-rail">
        <?php foreach ($recCards as $c): $renderCard($c[0], $c[1], $c[2], $c[3]); endforeach; ?>
    </div>
<?php endif; ?>

<?php
// セクション見出し（タブ／検索状態で切り替え）。
if ($hasQuery) {
    echo '<p class="tp-count"><b>' . count($results) . '</b> 名がヒット <span style="color:var(--faint);font-size:.8rem;">・ポイントの高い方が上位</span></p>';
} else {
    $secTitle = ['osusume' => 'すべての会員', 'kininaru' => '気になる', 'footprint' => '足あと（あなたを見た人）', 'new' => '新着メンバー', 'ranking' => 'ポイントランキング'][$tab];
    echo '<div class="tp-secttl">';
    echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 3-5.5 6.5-5.5S15.5 16.4 15.5 20"/><circle cx="17" cy="8.5" r="3"/><path d="M17 14.5c3.2 0 5 1.9 5 5.5"/></svg>';
    echo e($secTitle) . ' <span class="more" style="color:var(--faint);font-weight:700;">' . count($results) . '名</span>';
    echo '</div>';
}
?>

<?php if ($results === []): ?>
    <div class="card"><p style="margin:0;">
        <?php if ($tab === 'kininaru' && !$hasQuery): ?>
            まだ「気になる」した会員がいません。カードの♡を押すとここに集まります。
        <?php elseif ($tab === 'footprint' && !$hasQuery): ?>
            まだ足あとはありません。あなたのプロフィールを見た会員がここに表示されます。
        <?php else: ?>
            条件に合う会員が見つかりませんでした。条件を変えてお試しください。
        <?php endif; ?>
    </p></div>
<?php else: ?>
    <div class="tp-grid">
        <?php foreach ($results as $i => $r): $renderCard((string) $r['member_id'], (string) ($r['name_text'] ?? ''), (string) ($r['age_text'] ?? ''), ($r['photo_status'] ?? '') === 'approved', $isRanking ? $i + 1 : 0); endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
