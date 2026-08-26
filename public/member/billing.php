<?php

/**
 * お支払い・解約の管理（会員）。
 * Stripe カスタマーポータルへ誘導し、カード変更・請求書閲覧・解約をセルフサービスで行えるようにする。
 * 解約結果は Webhook（customer.subscription.updated/deleted）で会員状態に反映される。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member(false, true); // 無料フェーズ/未サブスクでも状態確認のため開ける
$customerId = (string) ($member['stripe_customer_id'] ?? '');
$subStatus = (string) ($member['subscription_status'] ?? '');
$waived = (int) ($member['subscription_waived'] ?? 0) === 1;
$earned = member_waiver_earned($member);      // 紹介特典の資格（一度達成したら消えない）
$canSubscribe = member_can_subscribe_now($member); // 猶予期間・課金フェーズで、まだ未登録なら true
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
                'return_url' => base_url() . '/member/billing',
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

<?php $__grace = billing_grace_notice(); ?>
<?php if ($__grace !== ''): ?>
    <div class="flash flash--ng"><?= e($__grace) ?>それまでは今までどおりご利用いただけます。</div>
<?php endif; ?>

<div class="card">
    <div class="card__title">現在のご契約</div>
    <?php $curPlan = member_plan($member); ?>
    <p style="margin:.3rem 0;">プラン：<span class="badge badge--<?= billing_started() && $curPlan !== 'premium' ? 'mute' : 'info' ?>"><?= e(ops_member_status_label($member)) ?></span>
        <?php if (!billing_started()): ?><span class="muted" style="font-size:.82rem;">（全機能をご利用いただけます）</span><?php endif; ?>
    </p>
    <p style="margin:.3rem 0;">
        月額会費：<strong><?= e($statusLabel) ?></strong>
        <?php if ($waived): ?>
            <span class="badge badge--info">紹介特典で無料</span>
        <?php endif; ?>
    </p>
    <?php if (billing_started() && $curPlan !== 'premium'): ?>
        <p class="muted" style="font-size:.85rem;margin-top:8px;">プレミアムにすると、おすすめの表示数無制限・全条件での検索・一覧での優先表示などがご利用いただけます。アップグレードをご希望の場合は運営までご連絡ください。</p>
    <?php endif; ?>
    <?php if ($waived || $earned): ?>
        <p class="muted" style="margin:.3rem 0;">ご紹介の条件（<?= (int) referral_waiver_min() ?>名）を達成いただいたため、月額会費は<strong>無料</strong>です。
            一度条件を満たすと、<strong>その後はずっと無料</strong>のままです（あとでご紹介先が減っても通常額には戻りません）。</p>
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
<?php endif; ?>

<?php if ($canSubscribe): ?>
<div class="card">
    <div class="card__title">月額会費のご登録</div>
    <?php if (billing_grace_active()): ?>
        <p style="margin:.3rem 0;"><?= e(billing_grace_notice()) ?>いまお申し込みいただいても、<strong>最初のご請求は課金開始日から</strong>です。</p>
    <?php else: ?>
        <p style="margin:.3rem 0;">現在、月額会費のご登録はありません。ご登録いただくと「さがす」がご利用いただけます。</p>
    <?php endif; ?>
    <p style="margin-top:12px;"><a class="btn" href="/member/subscribe">月額会費を登録する</a></p>
</div>
<?php elseif ($customerId === ''): ?>
<div class="card">
    <p class="muted" style="margin:0;">現在、月額会費のご登録はありません。</p>
</div>
<?php endif; ?>

<p class="muted" style="margin-top:10px;"><a href="/member/dashboard">← 会員トップへ戻る</a></p>
<?php require __DIR__ . '/_footer.php'; ?>
