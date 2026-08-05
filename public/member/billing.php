<?php

/**
 * お支払い・解約の管理（会員）。
 * Stripe カスタマーポータルへ誘導し、カード変更・請求書閲覧・解約をセルフサービスで行えるようにする。
 * 解約結果は Webhook（customer.subscription.updated/deleted）で会員状態に反映される。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member(false, true); // 無料フェーズ/未サブスクでも状態確認のため開ける
$customerId = (string) ($member['stripe_customer_id'] ?? '');
$subStatus = (string) ($member['subscription_status'] ?? '');
$waived = (int) ($member['subscription_waived'] ?? 0) === 1;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($customerId === '') {
        $error = '現在、お客様のお支払い情報が見つかりません。';
    } elseif (!rate_limit_check('billing_portal', 10, 3600)) {
        $error = '試行が多すぎます。しばらく時間をおいてお試しください。';
    } else {
        try {
            init_stripe();
            $session = \Stripe\BillingPortal\Session::create([
                'customer'   => $customerId,
                'return_url' => base_url() . '/member/billing.php',
            ]);
            header('Location: ' . $session->url);
            exit;
        } catch (\Throwable $e) {
            error_log('billing portal error: ' . $e->getMessage());
            $error = 'お支払い管理ページを開けませんでした。時間をおいて再度お試しください。';
        }
    }
}

$statusLabel = [
    'active'   => '有効',
    'past_due' => 'お支払い確認中',
    'canceled' => '解約済み',
    'unpaid'   => '未払い',
][$subStatus] ?? ($subStatus !== '' ? $subStatus : '未登録');

$token = csrf_token();
$pageTitle = 'お支払い・解約の管理';
$showLogout = true;
require __DIR__ . '/_header.php';
?>
<h1>お支払い・解約の管理</h1>
<?php if ($error !== ''): ?><div class="flash flash--ng"><?= e($error) ?></div><?php endif; ?>

<div class="card">
    <div class="card__title">現在のご契約</div>
    <p style="margin:.3rem 0;">
        月額会費：<strong><?= e($statusLabel) ?></strong>
        <?php if ($waived): ?>
            <span class="badge" style="background:#eef2ff;color:#3730a3;">紹介特典で無料</span>
        <?php endif; ?>
    </p>
    <?php if ($waived): ?>
        <p class="muted" style="margin:.3rem 0;">アクティブな紹介先を5名以上維持いただいているため、現在の月額会費は<strong>無料</strong>です。紹介先が5名未満になると通常額に戻ります。</p>
    <?php endif; ?>
</div>

<?php if ($customerId !== ''): ?>
<div class="card">
    <div class="card__title">お支払い方法の変更・解約</div>
    <p class="muted">カード情報の変更、請求書の確認、<strong>解約</strong>は Stripe の安全な管理ページで行えます。</p>
    <form method="post" style="margin-top:12px;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <button type="submit" class="btn">お支払い管理ページを開く（Stripe）</button>
    </form>
    <p class="muted" style="margin-top:12px;font-size:.85rem;">解約すると、次回以降の請求が停止します（現在の請求期間の終了まではご利用いただけます）。</p>
</div>
<?php else: ?>
<div class="card">
    <p class="muted" style="margin:0;">現在、月額会費のご登録はありません。</p>
</div>
<?php endif; ?>

<p class="muted" style="margin-top:10px;"><a href="/member/dashboard.php">← 会員トップへ戻る</a></p>
<?php require __DIR__ . '/_footer.php'; ?>
