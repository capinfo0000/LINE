<?php

/**
 * 会員プロフィール詳細（会員のみ閲覧）。LINE追加URLは所有者の表示設定に従う。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$viewer = require_member();
$targetId = (string) ($_GET['id'] ?? '');
$evalMsg = '';
$evalType = 'ok';

// 自己紹介を公式LINEに送るまで、他会員のプロフィールは見せない（自分のプレビューは可）。
if ((string) $viewer['id'] !== $targetId && member_needs_intro($viewer)) {
    header('Location: /member/directory.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $targetId !== '') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'praise' || $act === 'report') {
        $r = evaluate_member((string) $viewer['id'], $targetId, $act, (string) ($_POST['note'] ?? ''));
        $evalMsg = $r['message'];
        $evalType = $r['ok'] ? 'ok' : 'ng';
    } elseif ($act === 'interest') {
        $on = toggle_interest((string) $viewer['id'], $targetId);
        $evalMsg = $on ? '「気になる」を送りました。' : '「気になる」を取り消しました。';
        $evalType = 'ok';
    }
}

// 本人のプレビューは、ディレクトリ非掲載でも常に閲覧できるようにする。
if ($targetId !== '' && (string) $viewer['id'] === $targetId) {
    $selfMember = find_member_by_id($targetId);
    $view = $selfMember !== null ? ['member' => $selfMember, 'profile' => get_profile($targetId)] : null;
} else {
    $view = $targetId !== '' ? viewable_member_profile($targetId) : null;
}
if ($view === null) {
    http_response_code(404);
    $pageTitle = '会員が見つかりません';
    $showLogout = true;
    require __DIR__ . '/_header.php';
    echo '<div class="card"><p>指定の会員は表示できません。</p></div>';
    echo '<p><a href="/member/directory.php">← ディレクトリへ戻る</a></p>';
    require __DIR__ . '/_footer.php';
    exit;
}

$member = $view['member'];
$profile = $view['profile'];

// 足あとを記録（自分自身の閲覧は除く）。GET表示時のみ。
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string) $viewer['id'] !== $targetId) {
    record_member_view((string) $viewer['id'], $targetId);
}

$labels = member_tag_labels($targetId);
$links = visible_member_links($targetId, $profile);
$hasApprovedPhoto = ($profile['photo_status'] ?? '') === 'approved';

$pageTitle = ($profile['name_text'] !== '' ? $profile['name_text'] : '会員') . ' さんのプロフィール';
$showLogout = true;
$hideBrand = true;
require __DIR__ . '/_header.php';
?>
<?php
$targetBalance = member_points_earned($targetId); // 累計獲得（称号の基準・実績）
$targetTitle = points_title($targetBalance);
$targetPraise = praise_count($targetId);
$iAmSelf = (string) $viewer['id'] === $targetId;
$rankClass = ['プラチナ' => 'rank--plat', 'ゴールド' => 'rank--gold', 'レギュラー' => 'rank--reg', 'ルーキー' => 'rank--rookie'][$targetTitle] ?? 'rank--rookie';
$nm = $profile['name_text'] !== '' ? $profile['name_text'] : '会員';
$ini = mb_substr($nm, 0, 1);
$hue = crc32($targetId) % 360;
$hue2 = ($hue + 38) % 360;
$heroStyle = $hasApprovedPhoto ? '' : ' style="background:linear-gradient(150deg,hsl(' . $hue . ' 66% 54%),hsl(' . $hue2 . ' 64% 45%))"';
$area = $labels['area'][0] ?? '';
$job = $labels['job'][0] ?? '';
$interested = !$iAmSelf && has_interest((string) $viewer['id'], $targetId);
$praised = !$iAmSelf && has_evaluated((string) $viewer['id'], $targetId, 'praise');
$reported = !$iAmSelf && has_evaluated((string) $viewer['id'], $targetId, 'report');
$lineUrl = '';
foreach ($links as $l) {
    if (($l['kind'] ?? '') === 'line_add' && ($l['url'] ?? '') !== '') { $lineUrl = (string) $l['url']; break; }
}
$csrf = csrf_token();
?>
<?php if ($evalMsg !== ''): ?><div class="flash <?= $evalType === 'ok' ? 'flash--ok' : 'flash--ng' ?>" style="margin-bottom:10px;"><?= e($evalMsg) ?></div><?php endif; ?>

<?php
$coverAbs = member_image_abs_path($profile, 'cover_path');
$avatarBg = $hasApprovedPhoto ? '' : ' style="background:linear-gradient(150deg,hsl(' . $hue . ' 66% 54%),hsl(' . $hue2 . ' 64% 45%))"';
?>
<!-- インスタ風ヘッダー：カバー画像＋丸アイコン重ね -->
<div class="tp-ig">
    <div class="tp-ig-cover"<?= $coverAbs === null ? ' style="background:linear-gradient(120deg,#fdba74,#f97316)"' : '' ?>>
        <?php if ($coverAbs !== null): ?><img src="/member/photo.php?id=<?= e($targetId) ?>&kind=cover&v=<?= (int)($profile['updated_at'] ?? 0) ?>" alt="カバー画像"><?php endif; ?>
        <a class="tp-hback" href="/member/directory.php" aria-label="ディレクトリへ戻る">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
    </div>
    <div class="tp-ig-head">
        <div class="tp-ig-av"<?= $avatarBg ?>>
            <?php if ($hasApprovedPhoto): ?><img src="/member/photo.php?id=<?= e($targetId) ?>&v=<?= (int)($profile['updated_at'] ?? 0) ?>" alt=""><?php else: ?><span><?= e($ini) ?></span><?php endif; ?>
        </div>
        <?php $ageDisp = (string) ($profile['age_text'] ?? ''); if ($ageDisp !== '' && ctype_digit($ageDisp)) { $ageDisp .= '歳'; } ?>
        <div class="tp-ig-name"><b><?= e($nm) ?></b><?php if ($ageDisp !== ''): ?><span class="age"><?= e($ageDisp) ?></span><?php endif; ?></div>
        <div class="tp-ig-pills">
            <?php if ($area !== ''): ?><span class="tp-pill">📍<?= e($area) ?></span><?php endif; ?>
            <?php if ($job !== ''): ?><span class="tp-pill"><?= e($job) ?></span><?php endif; ?>
            <span class="tp-pill<?= $targetTitle === 'ゴールド' || $targetTitle === 'プラチナ' ? ' tp-pill--gold' : '' ?>"><?= e($targetTitle) ?></span>
        </div>
    </div>
</div>

<?php if (($profile['headline'] ?? '') !== ''): ?>
<section class="tp-sec"><p class="tp-lead"><?= e($profile['headline']) ?></p></section>
<?php endif; ?>

<?php if (($profile['bio'] ?? '') !== ''): ?>
<section class="tp-sec">
    <h2 class="tp-sec__t">自己紹介</h2>
    <p class="tp-bio"><?= e($profile['bio']) ?></p>
</section>
<?php endif; ?>

<?php
$tagGroups = [
    ['エリア・業種', array_merge($labels['area'] ?? [], $labels['job'] ?? []), 'tp-t'],
    ['求めていること', $labels['purpose'] ?? [], 'tp-t tp-t--want'],
    ['提供できること', $labels['offer'] ?? [], 'tp-t tp-t--offer'],
];
$hasAnyTag = ($labels['area'] ?? []) || ($labels['job'] ?? []) || ($labels['purpose'] ?? []) || ($labels['offer'] ?? []);
?>
<?php if ($hasAnyTag): ?>
<section class="tp-sec">
    <h2 class="tp-sec__t">タグ</h2>
    <?php foreach ($tagGroups as [$glabel, $items, $cls]): ?>
        <?php if ($items !== []): ?>
        <div class="tp-grp">
            <p class="tp-grp__l"><?= e($glabel) ?></p>
            <div class="tp-tagwrap"><?php foreach ($items as $lb): ?><span class="<?= $cls ?>"><?= e($lb) ?></span><?php endforeach; ?></div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php $occ = (string) ($profile['occupation'] ?? ''); ?>
<?php if ($occ !== '' || $area !== '' || $ageDisp !== ''): ?>
<section class="tp-sec">
    <h2 class="tp-sec__t">基本情報</h2>
    <dl class="tp-kv">
        <?php if ($occ !== ''): ?><dt>職業</dt><dd><?= e($occ) ?></dd><?php endif; ?>
        <?php if ($area !== ''): ?><dt>エリア</dt><dd><?= e($area) ?></dd><?php endif; ?>
        <?php if ($ageDisp !== ''): ?><dt>年齢</dt><dd><?= e($ageDisp) ?></dd><?php endif; ?>
    </dl>
</section>
<?php endif; ?>

<?php $cardAbs = member_image_abs_path($profile, 'card_path'); ?>
<?php if ($cardAbs !== null): ?>
<section class="tp-sec">
    <h2 class="tp-sec__t">名刺</h2>
    <img src="/member/photo.php?id=<?= e($targetId) ?>&kind=card&v=<?= (int)($profile['updated_at'] ?? 0) ?>" alt="名刺画像" style="max-width:100%;border-radius:12px;box-shadow:var(--shadow-sm);">
</section>
<?php endif; ?>

<section class="tp-sec">
    <h2 class="tp-sec__t">実績・信頼</h2>
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
        <span><strong style="font-size:1.3rem;"><?= number_format($targetBalance) ?></strong> <span class="muted" style="font-size:.82rem;">pt</span></span>
        <span class="rank <?= $rankClass ?>" style="font-size:.82rem;padding:3px 11px;"><?= e($targetTitle) ?></span>
        <span><strong style="font-size:1.1rem;"><?= (int) $targetPraise ?></strong> <span class="muted" style="font-size:.82rem;">件の高評価</span></span>
    </div>
</section>

<?php if ($links !== []): ?>
<section class="tp-sec">
    <h2 class="tp-sec__t">リンク・連絡先</h2>
    <?php foreach ($links as $l): ?>
        <div class="tp-linkrow">
            <?php if (($l['kind'] ?? '') === 'line_add'): ?><span class="tp-linetag">LINE</span><?php endif; ?>
            <?php if (($l['label'] ?? '') !== ''): ?><span class="muted" style="font-size:.85rem;"><?= e($l['label']) ?></span><?php endif; ?>
            <a href="<?= e($l['url']) ?>" target="_blank" rel="noopener nofollow" style="word-break:break-all;"><?= e($l['url']) ?></a>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($iAmSelf): ?>
    <p style="text-align:center;margin:16px 0;"><a class="btn btn--ghost" href="/member/profile.php">プロフィールを編集</a></p>
<?php else: ?>
    <nav class="tp-actions" aria-label="アクション">
        <div class="tp-fabwrap">
            <form method="post" style="margin:0;" data-confirm="この会員を評価します（＋<?= points_amount('praise') ?>pt）。よろしいですか？">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="praise">
                <button class="tp-fab tp-fab--sm" aria-label="評価する" <?= $praised ? 'disabled' : '' ?>>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 10v11M2 13v6a2 2 0 0 0 2 2h13.3a2 2 0 0 0 2-1.7l1.1-7a2 2 0 0 0-2-2.3H14l.9-4.5A2 2 0 0 0 13 3l-4 7H2z"/></svg>
                </button>
            </form>
            <span class="tp-fablbl"><?= $praised ? '評価済' : '評価' ?></span>
        </div>
        <div class="tp-fabwrap">
            <form method="post" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="interest">
                <button class="tp-fab tp-fab--like<?= $interested ? ' on' : '' ?>" aria-label="気になる">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="<?= $interested ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12a1 1 0 0 1 1 1v16l-7-4-7 4V4a1 1 0 0 1 1-1z"/></svg>
                </button>
            </form>
            <span class="tp-fablbl"><?= $interested ? '気になる済' : '気になる' ?></span>
        </div>
        <?php if ($lineUrl !== ''): ?>
        <div class="tp-fabwrap">
            <a class="tp-fab tp-fab--line" href="<?= e($lineUrl) ?>" target="_blank" rel="noopener nofollow" aria-label="LINEでつながる">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </a>
            <span class="tp-fablbl">つながる</span>
        </div>
        <?php endif; ?>
    </nav>
    <p style="text-align:center;margin:6px 0 16px;">
        <form method="post" style="display:inline;margin:0;" data-confirm="この会員を通報します（1回のみ・取り消せません）。よろしいですか？">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="report">
            <button class="link-quiet" style="background:0;border:0;color:var(--muted);font-size:.8rem;cursor:pointer;text-decoration:underline;" <?= $reported ? 'disabled' : '' ?>><?= $reported ? '通報済み' : 'この会員を通報する' ?></button>
        </form>
    </p>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
