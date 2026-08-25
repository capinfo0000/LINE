<?php

/**
 * 会員ディレクトリ（条件検索）。有効会員限定・ディレクトリ掲載ONの会員を検索する。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

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
        <p style="margin:0 0 10px;"><a class="btn btn--lg" href="/member/intro?gate=1">自己紹介を送る（ひな形を開く）</a></p>
        <p style="margin:0;"><a class="btn btn--ghost" href="/member/directory">送信した／再読み込みして確認</a></p>
        <p class="muted" style="font-size:.8rem;margin:10px 0 0;">送信するとすぐに反映されます。この画面は自動で切り替わらないため、上のボタンで再読み込みしてください。</p>
        <?php if (site_setting('line_official_url') === '' && (string) (env('LINE_OFFICIAL_URL', '') ?? '') === ''): ?>
            <p class="muted" style="font-size:.8rem;margin:6px 0 0;">
                送り先は、入会のご案内をお送りした<strong>Enlink の公式アカウントのトーク</strong>です。
                ひな形の画面でコピーして、LINEアプリから貼り付けて送ってください。
            </p>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/_footer.php';
    exit;
}

// 課金フェーズに入ったあと、月額が未加入なら「さがす」関連は使えない。
// マイページ・プロフィール編集・ポイント・支払いは引き続き使えるので、
// ここでも黙って飛ばさず、画面のまま理由と支払いへの導線を出す。
if (member_search_locked($member)) {
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
        <div class="card__title" style="justify-content:center;">「さがす」は月額会員のみご利用いただけます</div>
        <p class="muted" style="margin:0 auto 16px;max-width:24em;">
            先着<?= (int) billing_free_limit() ?>名の無料期間が終了しました。会員どうしの検索・閲覧を続けるには、
            月額会費（<?= e(monthly_fee_text()) ?>）のご登録が必要です。<br>
            プロフィールの編集やポイントの確認は、引き続きご利用いただけます。
        </p>
        <p style="margin:0 0 10px;"><a class="btn btn--lg" href="/member/subscribe">月額会費を登録する</a></p>
        <p style="margin:0;"><a class="btn btn--ghost" href="/member/dashboard">マイページへ戻る</a></p>
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
    $ret = (string) ($_POST['return'] ?? '/member/directory');
    if (!preg_match('#^/member/[A-Za-z0-9_./?=&%+-]*$#', $ret)) {
        $ret = '/member/directory'; // オープンリダイレクト対策
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
    // 新着は直近の10名まで。
    $results = search_directory([], $member['id'], 10, 'new');
} elseif ($tab === 'ranking') {
    // ランキングは上位50名まで。
    $results = search_directory([], $member['id'], 50, 'points');
    $isRanking = true;
} else {
    // 「すべての会員」は初期30名。右上の「すべて見る」で全件（?all=1）に切り替える。
    // 続きがあるかは、1件多く取って判断する（総数を数えるために全件を引き直さない）。
    $showAll = isset($_GET['all']);
    $perPage = 30;
    $results = search_directory([], $member['id'], $showAll ? 500 : $perPage + 1);
    $hasMore = !$showAll && count($results) > $perPage;
    if ($hasMore) {
        $results = array_slice($results, 0, $perPage);
    }
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
/**
 * 会員カード1枚を出力（さがすグリッド／おすすめカルーセルで共通利用）。$rank>0 で順位バッジ表示。
 * 引数は検索結果・おすすめの行そのもの（member_id / public_code / name_text などを含む）。
 */
$renderCard = function (array $row, int $rank = 0) use ($member, $rankClass): void {
    $mid = (string) $row['member_id'];
    $nm = (string) ($row['name_text'] ?? '');
    $age = profile_age_text($row);
    $hasPhoto = profile_has_photo($row);
    $labels = member_tag_labels($mid);
    $title = member_title_by_id($mid);
    $nm = $nm !== '' ? $nm : '会員';
    $ini = mb_substr($nm, 0, 1);
    // 写真が無い会員のカード背景。会員ごとに少し色味を変えつつ、
    // 一覧がオレンジ基調なので暖色（赤〜黄）の範囲に収める（以前は全色相で青や緑が混ざっていた）。
    $hue = 16 + (crc32($mid) % 34);   // 16〜49度＝オレンジ〜アンバー
    $hue2 = $hue + 14;
    $cardBg = $hasPhoto ? '' : ' style="background:linear-gradient(150deg,hsl(' . $hue . ' 72% 52%),hsl(' . $hue2 . ' 68% 42%))"';
    $area = $labels['area'][0] ?? '';
    $job = $labels['job'][0] ?? '';
    $want = $labels['purpose'][0] ?? '';
    $liked = has_interest((string) $member['id'], $mid);
    ?>
    <div class="tp-card"<?= $cardBg ?>>
        <?php if ($hasPhoto): ?><img src="<?= e(member_photo_url($row)) ?>" alt=""><?php else: ?><span class="tp-ini"><?= e($ini) ?></span><?php endif; ?>
        <?php if ($rank > 0): ?><span class="tp-rankno<?= $rank <= 3 ? ' top' : '' ?>"><?= $rank ?></span><?php endif; ?>
        <a class="tp-cardlink" href="<?= e(member_public_path($row)) ?>" aria-label="<?= e($nm) ?> のプロフィール"></a>
        <span class="tp-crank <?= $rankClass($title) ?>"><?= e($title) ?></span>
        <div class="tp-cinfo">
            <?php if ($age !== '' || $area !== ''): ?><div class="aa"><?= e($age) ?><?= ($age !== '' && $area !== '') ? '・' : '' ?><?= e($area) ?></div><?php endif; ?>
            <div class="nm"><?= e($nm) ?></div>
            <div class="tp-ptags">
                <?php if ($job !== ''): ?><span class="tp-ptag tp-ptag--on"><?= e($job) ?></span><?php endif; ?>
                <?php if ($want !== ''): ?><span class="tp-ptag">求む・<?= e($want) ?></span><?php endif; ?>
            </div>
        </div>
        <button class="tp-clike<?= $liked ? ' on' : '' ?>" form="likeform" type="submit" name="like_to" value="<?= e($mid) ?>" aria-label="気になる">
            <svg viewBox="0 0 24 24" fill="<?= $liked ? '#fff' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12a1 1 0 0 1 1 1v16l-7-4-7 4V4a1 1 0 0 1 1-1z"/></svg>
        </button>
    </div>
    <?php
};
// 「おすすめ」タブ（検索なし）でだけ「あなたへのおすすめ」（双方向マッチ）を横スクロールで出す。
// マッチが無ければ全会員の上位をフォールバック表示して、常にカルーセルが出るようにする。
$showRail = !$hasQuery && $tab === 'osusume';
$recCards = [];
if ($showRail) {
    // 相手の「提供できること」が自分の「求めていること」と噛み合う人だけを出す。
    // 噛み合う人がいなければ、無関係な会員で埋めずに枠ごと出さない。
    foreach (recommend_offer_matches((string) $member['id'], 10) as $rc) {
        $recCards[] = $rc;
    }
}
?>
<form id="likeform" method="post" hidden>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '/member/directory') ?>">
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
                <p class="muted" style="font-size:.8rem;margin:10px 0 0;">仕事ジャンル・目的・キーワード検索は<strong>プレミアム</strong>限定です。<a href="/member/billing">プレミアムにする</a></p>
            <?php endif; ?>
            <div class="tp-filteractions">
                <button type="submit" class="btn">この条件で検索</button>
                <a class="btn btn--ghost" href="/member/directory">クリア</a>
            </div>
        </div>
    </details>
</form>

<!-- カテゴリタブ（横スクロール） -->
<nav class="tp-cat" aria-label="カテゴリ">
    <?php foreach ($tabs as $k => $label): ?>
        <a href="/member/directory?tab=<?= e($k) ?>" class="tp-cattab<?= (!$hasQuery && $tab === $k) ? ' on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$hasQuery && $tab === 'osusume'): ?>
    <!-- ヒーローカルーセル（スワイプ＋自動送り・CSPセーフ）。中身は管理画面から編集する。 -->
    <?php $announcements = active_announcements(); ?>
    <?php if ($announcements !== []): ?>
        <div class="tp-promo" role="region" aria-label="お知らせ" data-autoplay="6000">
            <?php foreach ($announcements as $an): ?>
                <?php
                $anUrl = (string) $an['url'];
                $anTag = $anUrl !== '' ? 'a' : 'div';
                $anTheme = isset(announcement_themes()[$an['theme']]) ? (string) $an['theme'] : 'brand';
                ?>
                <<?= $anTag ?> class="tp-pslide tp-pslide--<?= e($anTheme) ?>"<?= $anUrl !== '' ? ' href="' . e($anUrl) . '"' : '' ?>>
                    <?php if ((string) $an['label'] !== ''): ?><span class="tp-slabel"><?= e($an['label']) ?></span><?php endif; ?>
                    <b><?= e($an['title']) ?></b>
                    <?php if ((string) $an['subtitle'] !== ''): ?><span class="tp-ssub"><?= e($an['subtitle']) ?></span><?php endif; ?>
                </<?= $anTag ?>>
            <?php endforeach; ?>
        </div>
        <?php if (count($announcements) > 1): ?>
            <?php // 位置を示す点。JSが表示中のスライドに合わせて動かし、タップでその枚へ移動する。 ?>
            <div class="tp-dots" data-dots-for="tp-promo">
                <?php foreach ($announcements as $i => $an): ?><span<?= $i === 0 ? ' class="on"' : '' ?>></span><?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- キャンペーンバー -->
    <?php if (billing_started()): ?>
        <!-- 課金フェーズ：紹介で月額を無料にできるので、キャンペーンを出す。 -->
        <a class="tp-coupon" href="/member/points">
            <span class="tp-cbadge">紹介</span>
            <span class="tp-ctext"><b>5人ご紹介で翌月の月額が0円</b>／紹介はずっと有効</span>
            <span class="tp-chelp" aria-hidden="true">?</span>
        </a>
    <?php elseif (billing_grace_active()): ?>
        <!-- 猶予期間：いつから有料になるかを先に伝える。 -->
        <a class="tp-coupon tp-coupon--notice" href="/member/billing">
            <span class="tp-cbadge">お知らせ</span>
            <span class="tp-ctext"><b><?= e(billing_grace_notice()) ?></b></span>
            <span class="tp-chelp" aria-hidden="true">?</span>
        </a>
    <?php else: ?>
        <!-- 無料フェーズ：先着枠の残りを進捗バーで見せる。 -->
        <?php $bp = billing_progress(); ?>
        <div class="tp-progress">
            <div class="tp-progress__top">
                <span class="tp-cbadge">先着<?= (int) $bp['limit'] ?>名</span>
                <b>いまなら無料でご利用いただけます</b>
            </div>
            <div class="tp-progress__bar"><span style="width:<?= (int) $bp['percent'] ?>%;"></span></div>
            <div class="tp-progress__foot">
                <span><?= (int) $bp['count'] ?> / <?= (int) $bp['limit'] ?> 名</span>
                <span>あと<b><?= (int) $bp['remaining'] ?></b>名で締め切り</span>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($showRail && $recCards !== []): ?>
    <div class="tp-secttl">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 15 8.5 22 9.3l-5 4.6 1.3 6.9L12 17.8 5.7 20.8 7 13.9 2 9.3l7-.8z"/></svg>
        あなたへのおすすめ
        <a class="more" href="/member/recommend">すべて見る →</a>
    </div>
    <div class="tp-rail">
        <?php foreach ($recCards as $c): $renderCard($c); endforeach; ?>
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
    echo e($secTitle);
    // 右上に出すのは「すべて見る」だけ。件数は出さない。
    // 「すべての会員」は初期30名なので、続きがあるときだけ出す。
    if ($tab === 'osusume' && !empty($hasMore)) {
        echo ' <a class="more" href="/member/directory?all=1">すべて見る →</a>';
    }
    echo '</div>';
}
?>

<?php if ($results === []): ?>
    <div class="card"><p style="margin:0;">
        <?php if ($tab === 'kininaru' && !$hasQuery): ?>
            まだ「気になる」した会員がいません。カードのブックマークを押すとここに集まります。
        <?php elseif ($tab === 'footprint' && !$hasQuery): ?>
            まだ足あとはありません。あなたのプロフィールを見た会員がここに表示されます。
        <?php else: ?>
            条件に合う会員が見つかりませんでした。条件を変えてお試しください。
        <?php endif; ?>
    </p></div>
<?php else: ?>
    <div class="tp-grid">
        <?php foreach ($results as $i => $r): $renderCard($r, $isRanking ? $i + 1 : 0); endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
