<?php

/**
 * 会員トップ（ログイン後）。Phase 1 時点は最小の枠。
 * プロフィール編集・ディレクトリ検索・おすすめは後続フェーズで追加する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member(); // 未ログイン→login、初回PW未変更→change_password へ誘導
$flash = (string) ($_GET['msg'] ?? '');
$flashType = (string) ($_GET['type'] ?? '');

$pageTitle = '会員トップ';
$showLogout = true;
require __DIR__ . '/_header.php';
?>
<?php if ($flash !== ''): ?>
    <div class="flash <?= $flashType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<h1>ようこそ<?= $member['display_name'] !== '' ? '、' . e($member['display_name']) . ' さん' : '' ?></h1>

<?php
$__bal = member_points((string) $member['id']);           // 使えるポイント（残高）
$__earned = member_points_earned((string) $member['id']); // 称号の基準（累計獲得・下がらない）
?>
<div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
    <div>
        <div class="card__title" style="margin:0;">ポイント</div>
        <p style="margin:.2rem 0;"><strong style="font-size:1.4rem;"><?= number_format($__bal) ?></strong> pt <span class="muted" style="font-size:.8rem;">使えるポイント</span>　<span class="badge badge--title"><?= e(points_title($__earned)) ?></span></p>
    </div>
    <a class="btn btn--ghost" href="/member/points.php">ポイント・紹介</a>
</div>

<div class="card">
    <div class="card__title">プロフィール</div>
    <p>あなたの情報・タグ・求める条件・LINE追加URLを登録すると、条件に合う相手とつながりやすくなります。</p>
    <p>
        <a class="btn" href="/member/profile.php">プロフィールを編集</a>
        <a class="btn btn--ghost" href="/member/recommend.php">あなたへのおすすめ</a>
        <a class="btn btn--ghost" href="/member/directory.php">会員ディレクトリ</a>
    </p>
    <p class="muted">ログインID：<code><?= e($member['login_id']) ?></code></p>
</div>

<div class="card">
    <div class="card__title">アカウント</div>
    <p>
        <a class="btn btn--ghost" href="/member/change_password.php">パスワードを変更</a>
        <a class="btn btn--ghost" href="/member/billing.php">お支払い・解約</a>
    </p>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
