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

$pageTitle = 'ダッシュボード';
$pageSub = 'ようこそ、' . $tenant['display_name'] . ' さん';
require __DIR__ . '/_app_header.php';
?>
<?php if ($flash !== ''): ?>
    <div class="flash <?= $flashType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card__title">AKマッチング 運営コンソール</div>
    <p>会員制の人脈マッチングサービスの運営コンソールです。</p>
    <p class="muted">会員管理・予約枠・オープンチャットURL・一斉配信・統計などの機能は、
       今後のフェーズで順次追加されます。</p>
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
