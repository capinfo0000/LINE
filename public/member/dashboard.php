<?php

/**
 * マイページ（プロフィールタブ）。
 * 旧「会員トップ（ホーム）」の内容を統合し、自分のプロフィール概要＋ポイント＋各種メニューを表示する。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member(); // 未ログイン→login、初回PW未変更→change_password へ誘導
$flash = (string) ($_GET['msg'] ?? '');
$flashType = (string) ($_GET['type'] ?? '');

$id = (string) $member['id'];
$profile = get_profile($id);
$labels = member_tag_labels($id);
$bal = member_points($id);
$earned = member_points_earned($id);
$title = member_title($member);
$recv = received_interest_count($id);
$planLabel = ops_member_status_label($member);
$hasPhoto = profile_has_photo($profile);
$nm = ($profile['name_text'] ?? '') !== '' ? $profile['name_text'] : (($member['display_name'] ?? '') !== '' ? $member['display_name'] : '会員');
$ini = mb_substr($nm, 0, 1);
// 写真が無いときの下地。さがすのカードと同じく暖色（オレンジ〜アンバー）に収める。
$hue = 16 + (crc32($id) % 34);
$hue2 = $hue + 14;
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
        <?php if ($hasPhoto): ?><img src="/member/photo?v=<?= (int) ($profile['updated_at'] ?? 0) ?>" alt=""><?php else: ?><?= e($ini) ?><?php endif; ?>
    </div>
    <div class="tp-mp-name"><?= e($nm) ?></div>
    <?php if ($age !== '' || $area !== ''): ?>
        <div class="tp-mp-sub"><?= $age !== '' ? e($age) . '歳' : '' ?><?= ($age !== '' && $area !== '') ? '・' : '' ?><?= e($area) ?></div>
    <?php endif; ?>
    <span class="tp-mp-plan">会員ステータス：<strong><?= e($planLabel) ?></strong></span>
</div>

<div class="tp-tiles tp-tiles--4">
    <div class="tp-tile"><b><?= (int) $recv ?></b><span>気になる</span></div>
    <a class="tp-tile" href="/member/directory?tab=footprint"><b><?= (int) footprint_count($id) ?></b><span>足あと</span></a>
    <a class="tp-tile" href="/member/points"><b><?= number_format($bal) ?></b><span>ポイント</span></a>
    <a class="tp-tile" href="/member/points"><b style="font-size:.9rem;line-height:1.8;"><?= e($title) ?></b><span>称号</span></a>
</div>

<div class="tp-menu">
    <a href="<?= e(member_public_path($member)) ?>">👀 プロフィールを確認<span class="chev">›</span></a>
    <a href="/member/profile">✏️ プロフィールを編集<span class="chev">›</span></a>
    <a href="/member/intro">💬 自己紹介ひな形（LINE用）<span class="chev">›</span></a>
    <a href="/member/points">⭐ ポイント<?= billing_started() ? '・紹介' : '' ?><span class="chev">›</span></a>
    <a href="/member/billing">💳 お支払い・解約<span class="chev">›</span></a>
    <a href="/member/account">🪪 ログインID・パスワード<span class="chev">›</span></a>
    <a href="/member/feedback">📮 意見箱（ご意見・ご要望）<span class="chev">›</span></a>
    <a href="/member/logout">↩️ ログアウト<span class="chev">›</span></a>
</div>

<p class="muted" style="text-align:center;font-size:.8rem;">ログインID：<code><?= e($member['login_id']) ?></code></p>
<?php require __DIR__ . '/_footer.php'; ?>
