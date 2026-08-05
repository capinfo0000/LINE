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
    <div class="stat"><span class="stat__num"><?= (int) $stats['upcoming_bookings'] ?></span><span class="stat__label">今後の予約</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $stats['line_contacts'] ?></span><span class="stat__label">LINE友だち</span></div>
    <div class="stat"><span class="stat__num"><?= (int) $stats['push_this_month'] ?></span><span class="stat__label">今月のPush(課金)</span></div>
</div>

<?php
$__active = active_member_count();
$__limit = billing_free_limit();
$__billing = billing_started();
?>
<div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
    <div>
        <div class="card__title" style="margin:0;">料金フェーズ</div>
        <p style="margin:.2rem 0;">
            <?php if ($__billing): ?>
                <span class="badge" style="background:var(--ok-bg);color:var(--ok-fg);">課金フェーズ</span>
                有効会員 <strong><?= (int) $__active ?></strong> 名（全員サブスク登録が必要／未登録はアクセス制限）
            <?php else: ?>
                <span class="badge" style="background:#eef2ff;color:#3730a3;">無料フェーズ</span>
                有効会員 <strong><?= (int) $__active ?></strong> / <?= (int) $__limit ?> 名（あと <strong><?= max(0, $__limit - (int) $__active + 1) ?></strong> 名で課金開始）
            <?php endif; ?>
        </p>
    </div>
</div>

<?php $__reports = pending_reports(50); ?>
<?php if ($__reports !== []): ?>
<div class="card">
    <div class="card__title">未処理の通報（<?= count($__reports) ?> 件）</div>
    <?php foreach ($__reports as $rp): ?>
        <p style="margin:6px 0;border-bottom:1px solid var(--border);padding-bottom:6px;">
            <a href="member_detail.php?id=<?= e($rp['target_id']) ?>"><code><?= e($rp['target_login'] ?? '-') ?></code></a> への通報
            <span class="muted" style="font-size:.82rem;">（通報者 <?= e($rp['rater_login'] ?? '-') ?>・<?= e(date('m/d H:i', (int) $rp['created_at'] + 9 * 3600)) ?>）</span>
            <?php if (($rp['note'] ?? '') !== ''): ?><br><span class="muted" style="font-size:.85rem;"><?= e(mb_substr((string) $rp['note'], 0, 80)) ?></span><?php endif; ?>
        </p>
    <?php endforeach; ?>
    <p class="muted" style="font-size:.82rem;">各会員の詳細画面で「減点して処理／却下」できます。</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card__title">運営メニュー</div>
    <p>
        <a class="btn btn--ghost" href="members.php">会員管理</a>
        <a class="btn btn--ghost" href="slots.php">説明会・面談</a>
        <a class="btn btn--ghost" href="line_send.php">LINE配信</a>
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
