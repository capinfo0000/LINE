<?php

/**
 * マイページ（プロフィールタブ）。
 * 旧「会員トップ（ホーム）」の内容を統合し、自分のプロフィール概要＋ポイント＋各種メニューを表示する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member(); // 未ログイン→login、初回PW未変更→change_password へ誘導
$flash = (string) ($_GET['msg'] ?? '');
$flashType = (string) ($_GET['type'] ?? '');

$id = (string) $member['id'];
$profile = get_profile($id);
$labels = member_tag_labels($id);
$bal = member_points($id);
$earned = member_points_earned($id);
$title = points_title($earned);
$recv = received_interest_count($id);
$plan = member_plan($member);
$planLabel = billing_started() ? plan_label($plan) : '無料プラン';
$hasPhoto = ($profile['photo_status'] ?? '') === 'approved';
$nm = ($profile['name_text'] ?? '') !== '' ? $profile['name_text'] : (($member['display_name'] ?? '') !== '' ? $member['display_name'] : '会員');
$ini = mb_substr($nm, 0, 1);
$hue = crc32($id) % 360;
$hue2 = ($hue + 38) % 360;
$area = $labels['area'][0] ?? '';
$age = (string) ($profile['age_text'] ?? '');

$pageTitle = 'マイページ';
$showLogout = true;
$hideBrand = true;
require __DIR__ . '/_header.php';
?>
<?php if ($flash !== ''): ?>
    <div class="flash <?= $flashType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="tp-mp-head">
    <div class="tp-mp-av"<?= $hasPhoto ? '' : ' style="background:linear-gradient(150deg,hsl(' . $hue . ' 66% 54%),hsl(' . $hue2 . ' 64% 45%))"' ?>>
        <?php if ($hasPhoto): ?><img src="/member/photo.php?id=<?= e($id) ?>" alt=""><?php else: ?><?= e($ini) ?><?php endif; ?>
    </div>
    <div class="tp-mp-name"><?= e($nm) ?></div>
    <?php if ($age !== '' || $area !== ''): ?>
        <div class="tp-mp-sub"><?= $age !== '' ? e($age) . '歳' : '' ?><?= ($age !== '' && $area !== '') ? '・' : '' ?><?= e($area) ?></div>
    <?php endif; ?>
    <span class="tp-mp-plan">会員ステータス：<strong><?= e($planLabel) ?></strong></span>
</div>

<div class="tp-tiles tp-tiles--4">
    <div class="tp-tile"><b><?= (int) $recv ?></b><span>気になる（受）</span></div>
    <a class="tp-tile" href="/member/directory.php?tab=footprint"><b><?= (int) footprint_count($id) ?></b><span>足あと</span></a>
    <a class="tp-tile" href="/member/points.php"><b><?= number_format($bal) ?></b><span>ポイント</span></a>
    <a class="tp-tile" href="/member/points.php"><b style="font-size:1rem;line-height:1.9;"><?= e($title) ?></b><span>称号</span></a>
</div>

<div class="tp-menu">
    <a href="/member/member_view.php?id=<?= e($id) ?>">👀 プロフィールを確認<span class="chev">›</span></a>
    <a href="/member/profile.php">✏️ プロフィールを編集<span class="chev">›</span></a>
    <a href="/member/intro.php">💬 自己紹介ひな形（LINE用）<span class="chev">›</span></a>
    <a href="/member/points.php">⭐ ポイント<?= billing_started() ? '・紹介' : '' ?><span class="chev">›</span></a>
    <a href="/member/billing.php">💳 お支払い・解約<span class="chev">›</span></a>
    <a href="/member/account.php">🪪 ログインID・パスワード<span class="chev">›</span></a>
    <a href="/member/logout.php">↩️ ログアウト<span class="chev">›</span></a>
</div>

<p class="muted" style="text-align:center;font-size:.8rem;">ログインID：<code><?= e($member['login_id']) ?></code></p>
<?php require __DIR__ . '/_footer.php'; ?>
