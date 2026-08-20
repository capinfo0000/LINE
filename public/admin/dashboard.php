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

// 紹介判定モード（A案/B案）の切替。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'waiver_mode') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $mode = strtoupper((string) ($_POST['mode'] ?? 'A')) === 'B' ? 'B' : 'A';
    app_setting_set('referral_waiver_mode', $mode);
    header('Location: /admin/dashboard.php?msg=' . rawurlencode('紹介判定モードを ' . $mode . '案 に変更しました。') . '&type=ok');
    exit;
}

// 開発用サンプル会員（管理者のみ）。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['action'] ?? ''), ['seed_samples', 'delete_samples'], true)) {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ((int) ($tenant['is_admin'] ?? 0) !== 1) {
        header('Location: /admin/dashboard.php?msg=' . rawurlencode('権限がありません。') . '&type=ng');
        exit;
    }
    if ((string) $_POST['action'] === 'seed_samples') {
        $n = seed_sample_members();
        $m = "サンプル会員を {$n} 名投入しました。";
    } else {
        $n = delete_sample_members();
        $m = "サンプル会員を {$n} 名削除しました。";
    }
    header('Location: /admin/dashboard.php?msg=' . rawurlencode($m) . '&type=ok');
    exit;
}

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
$__subscribed = subscribed_member_count();
$__waived = waived_member_count();
$__paying = max(0, $__subscribed - $__waived);
$__freeRatio = $__subscribed > 0 ? round($__waived * 100 / $__subscribed) : 0;
$__mode = referral_waiver_mode();
$__csrf = csrf_token();
?>
<div class="card">
    <div class="card__title" style="margin:0;">料金フェーズ</div>
    <p style="margin:.4rem 0;">
        <?php if ($__billing): ?>
            <span class="badge" style="background:var(--ok-bg);color:var(--ok-fg);">課金フェーズ</span>
            有効会員 <strong><?= (int) $__active ?></strong> 名（全員サブスク登録が必要／未登録はアクセス制限）
        <?php else: ?>
            <span class="badge" style="background:#eef2ff;color:#3730a3;">無料フェーズ</span>
            有効会員 <strong><?= (int) $__active ?></strong> / <?= (int) $__limit ?> 名（あと <strong><?= max(0, $__limit - (int) $__active + 1) ?></strong> 名で課金開始）
        <?php endif; ?>
    </p>

    <?php if ($__billing): ?>
    <div style="display:flex;gap:18px;flex-wrap:wrap;margin:.6rem 0;">
        <div><span class="stat__num" style="font-size:1.3rem;"><?= (int) $__paying ?></span> <span class="muted">課金中</span></div>
        <div><span class="stat__num" style="font-size:1.3rem;"><?= (int) $__waived ?></span> <span class="muted">無料化（紹介特典）</span></div>
        <div><span class="stat__num" style="font-size:1.3rem;"><?= (int) $__freeRatio ?>%</span> <span class="muted">無料比率</span></div>
    </div>
    <?php if ($__freeRatio >= 40): ?>
        <p class="flash flash--ng" style="margin:.4rem 0;">無料比率が高くなっています。収益を守るには「B案（課金中のみカウント）」への切替を検討してください。</p>
    <?php endif; ?>
    <?php endif; ?>

    <div style="border-top:1px solid var(--border);margin-top:.6rem;padding-top:.6rem;">
        <div class="muted" style="font-size:.85rem;margin-bottom:6px;">紹介特典の判定モード（現在：<strong><?= e($__mode) ?>案</strong>）</div>
        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="csrf_token" value="<?= e($__csrf) ?>">
            <input type="hidden" name="action" value="waiver_mode">
            <button type="submit" name="mode" value="A" class="btn btn--sm <?= $__mode === 'A' ? '' : 'btn--ghost' ?>" data-confirm="A案（無料化した紹介先もカウント）に切り替えますか？">A案（拡散重視）</button>
            <button type="submit" name="mode" value="B" class="btn btn--sm <?= $__mode === 'B' ? '' : 'btn--ghost' ?>" data-confirm="B案（課金中の紹介先のみカウント）に切り替えますか？">B案（収益重視）</button>
        </form>
        <p class="muted" style="font-size:.78rem;margin:6px 0 0;">A案＝無料化した紹介先も5名に数える／B案＝実際に課金している紹介先だけ数える。次回のcron判定から反映されます。</p>
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
        <a class="btn btn--ghost" href="contacts.php">LINE申込者</a>
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
<?php if ((int) ($tenant['is_admin'] ?? 0) === 1): ?>
<?php $__samples = sample_member_count(); ?>
<div class="card">
    <div class="card__title">開発用：サンプル会員</div>
    <p class="muted" style="margin-top:0;">さがす／プロフィールの表示確認用のダミー会員です。現在 <strong><?= (int) $__samples ?></strong> 名。本番運用前に削除してください。</p>
    <p>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="seed_samples">
            <button class="btn btn--ghost" data-confirm="サンプル会員を投入します（既にある分は追加しません）。よろしいですか？">サンプル会員を投入</button>
        </form>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_samples">
            <button class="btn btn--danger" data-confirm="サンプル会員を全員削除します。よろしいですか？"<?= $__samples === 0 ? ' disabled' : '' ?>>サンプル会員を一括削除</button>
        </form>
    </p>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
