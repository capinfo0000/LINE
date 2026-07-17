<?php

/**
 * 運営者ダッシュボード（ログイン後のトップ）。
 * Phase 0 時点は最小の入口。会員管理・予約・配信などは後続フェーズで追加する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$flash = (string) ($_GET['msg'] ?? '');
$flashType = (string) ($_GET['type'] ?? '');

$stats = admin_stats();

$pageTitle = 'ダッシュボード';
$pageSub = 'ようこそ、' . $tenant['display_name'] . ' さん';
require __DIR__ . '/_app_header.php';
?>
<?php if ($flash !== ''): ?>
    <div class="flash <?= $flashType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat"><span class="stat__num accent"><?= (int) $stats['members_active'] ?></span><span class="stat__label">有効会員（全<?= (int) $stats['members_total'] ?>）</span></div>
    <div class="stat"><span class="stat__num"><?= e(format_amount((int) $stats['revenue'])) ?></span><span class="stat__label">入会金累計（<?= (int) $stats['payments_paid'] ?>件）</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $stats['pending_photos'] ?></span><span class="stat__label">写真承認待ち</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $stats['upcoming_bookings'] ?></span><span class="stat__label">今後の予約</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $stats['line_contacts'] ?></span><span class="stat__label">LINE友だち</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $stats['push_this_month'] ?></span><span class="stat__label">今月のPush(課金)</span></div>
</div>

<div class="card">
    <div class="card__title">運営メニュー</div>
    <p>
        <a class="btn btn--ghost" href="members.php">会員管理</a>
        <a class="btn btn--ghost" href="photos.php">写真承認<?= (int) $stats['pending_photos'] > 0 ? '（' . (int) $stats['pending_photos'] . '）' : '' ?></a>
        <a class="btn btn--ghost" href="slots.php">予約枠</a>
        <a class="btn btn--ghost" href="broadcast.php">一斉配信</a>
        <a class="btn btn--ghost" href="openchat.php">オープンチャット</a>
        <a class="btn btn--ghost" href="tags.php">タグ管理</a>
    </p>
</div>

<div class="card">
    <div class="card__title">アカウント</div>
    <p>
        <a class="btn btn--ghost" href="account.php">アカウント設定</a>
        <?php if ((int) ($tenant['is_admin'] ?? 0) === 1): ?>
            <a class="btn btn--ghost" href="invites.php">運営者を招待</a>
        <?php endif; ?>
    </p>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
