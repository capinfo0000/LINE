<?php

/**
 * 初期設定ウィザード（ログイン中テナント専用）。
 * 「Stripe設定 → イベント作成 → 公開リンク共有」までを案内し、各ステップの完了状況を表示する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();

$hasKey = tenant_has_stripe_key($tenant) || ($tenant['stripe_account_id'] ?? '') !== '';
$events = tenant_events($tenant['id']);
$hasEvent = count($events) > 0;
$publicUrl = base_url() . '/o.php?t=' . urlencode($tenant['id']);

$pageTitle = '初期設定';
$pageSub = '3ステップで受付を開始できます';
require __DIR__ . '/_app_header.php';

/** ステップ表示用のバッジ。 */
function step_badge(bool $done): string
{
    return $done
        ? '<span class="badge badge--ok">完了</span>'
        : '<span class="badge badge--warn">未完了</span>';
}
?>
<div class="card">
    <div class="card__title">① Stripe を設定する <?= step_badge($hasKey) ?></div>
    <p class="muted">参加費を受け取る Stripe アカウントの API キーを登録します（当日支払い・現金のみの運用なら任意）。</p>
    <p><a class="btn <?= $hasKey ? 'btn--ghost' : '' ?>" href="stripe.php"><?= $hasKey ? 'Stripe 設定を見直す' : 'Stripe を設定する' ?></a></p>
</div>

<div class="card">
    <div class="card__title">② イベントを作成する <?= step_badge($hasEvent) ?></div>
    <p class="muted">イベント名・日時・場所・参加費・支払い方法（事前決済／当日支払い）を登録します。</p>
    <p><a class="btn <?= $hasEvent ? 'btn--ghost' : '' ?>" href="events.php"><?= $hasEvent ? 'イベントを管理する' : 'イベントを作成する' ?></a></p>
</div>

<div class="card">
    <div class="card__title">③ 公開リンクを共有する <?= step_badge($hasEvent) ?></div>
    <p class="muted">このリンクを参加者に共有すると、開催中のイベント一覧から申し込めます。</p>
    <?php if ($hasEvent): ?>
        <input type="text" class="js-select" readonly value="<?= e($publicUrl) ?>">
        <p style="margin-top:10px;">
            <a class="btn btn--ghost" href="<?= e($publicUrl) ?>" target="_blank">公開ページを開く</a>
            <a class="btn btn--ghost" href="index.php">参加者管理へ</a>
        </p>
    <?php else: ?>
        <p class="muted">先にイベントを1件作成すると、共有リンクが有効になります。</p>
    <?php endif; ?>
</div>

<?php if ($hasKey && $hasEvent): ?>
    <div class="flash flash--ok">セットアップ完了です。<a href="dashboard.php">ダッシュボードへ</a></div>
<?php endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
