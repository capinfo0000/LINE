<?php

/**
 * 月額会費の登録。
 * ログイン中の会員が月額会費のサブスクに登録する。
 * 猶予期間（100名到達〜課金開始日）からご自分で登録でき、その場合の初回請求は
 * 課金開始日からになる（Stripe の trial_end で先送りする）。
 * すでに有効なら会員トップへ戻す。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member(false, true); // 未サブスクでも開ける
$memberId = (string) $member['id'];

// すでにサブスク有効、または課金制度がまだ始まっていないなら会員トップへ。
// ※ ロック判定（member_requires_subscription）とは別。猶予期間はロックしないが登録はできる。
if (!member_can_subscribe_now($member)) {
    header('Location: /member/dashboard');
    exit;
}

$priceId = (string) (env('STRIPE_PRICE_ID') ?? '');
$monthlyAmount = (int) env('MONTHLY_FEE_AMOUNT', '0');
$error = '';

// 初回請求を課金開始日まで先送りできるか（画面の案内を出し分けるために使う）。
// 実際に Checkout へ渡すのは create_subscription_checkout() の中。
$trialEnd = subscription_trial_end();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($priceId === '') {
        $error = '現在お申し込みを受け付けられません（設定未完了）。運営にお問い合わせください。';
    } elseif (!rate_limit_check('subscribe', 10, 3600)) {
        $error = '試行が多すぎます。しばらく時間をおいてお試しください。';
    } else {
        try {
            // 組み立ては create_subscription_checkout() に一本化している。
            // 運営が代理でリンクを発行する画面（admin/member_detail.php）と
            // 条件が食い違わないようにするため、ここでパラメータを組まない。
            $url = create_subscription_checkout(
                $member,
                base_url() . '/member/dashboard?msg=' . rawurlencode('月額登録が完了しました。') . '&type=ok',
                base_url() . '/member/subscribe'
            );
            header('Location: ' . $url);
            exit;
        } catch (\Throwable $e) {
            error_log('subscribe create error: ' . $e->getMessage());
            $error = '決済ページの作成に失敗しました。時間をおいて再度お試しください。';
        }
    }
}

$token = csrf_token();
$pageTitle = '月額会費のご登録';
$showLogout = true;
require __DIR__ . '/_header.php';
?>
<h1>月額会費のご登録</h1>
<?php if ($error !== ''): ?><div class="flash flash--ng"><?= e($error) ?></div><?php endif; ?>

<div class="card">
    <p>Enlink は会員数が一定に達したため、会員機能のご利用に<strong>月額会費（サブスクリプション）</strong><?php if ($monthlyAmount > 0): ?> <strong><?= e(format_amount($monthlyAmount)) ?> / 月</strong><?php endif; ?>が必要になります。</p>
    <?php if (billing_grace_active()): ?>
        <?php if ($trialEnd !== null): ?>
            <p class="flash flash--ok" style="margin:.6rem 0;">いまお申し込みいただいても、<strong>最初のご請求は<?= e(date('n月j日', $trialEnd)) ?>から</strong>です。それまでの分は頂きません。</p>
        <?php else: ?>
            <p class="flash flash--ok" style="margin:.6rem 0;">お申し込み手続きはいつでも中断・解約いただけます。</p>
        <?php endif; ?>
    <?php endif; ?>
    <p class="muted">ご登録いただくと、これまでどおり会員機能（ディレクトリ・おすすめ・プロフィール等）をご利用いただけます。いつでも解約できます。</p>
    <form method="post" style="margin-top:14px;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <button type="submit" class="btn">月額会費を登録する（Stripe）</button>
    </form>
    <p class="muted" style="margin-top:12px;">カード情報の入力・処理は Stripe 上で安全に行われます。当方はカード情報を保持しません。</p>
</div>

<div class="card">
    <?php if (member_qualifies_for_waiver($member)): ?>
        <p style="margin:0;"><span class="badge badge--info">紹介特典の条件を達成済み</span>
            このままお申し込みいただくと、<strong>最初のご請求から月額会費は無料</strong>になります。</p>
    <?php else: ?>
        <?php $__min = (int) referral_waiver_min(); ?>
        <p class="muted" style="margin:0;">ご紹介いただいた方が<strong><?= $__min ?>名</strong>会費のご登録をされると、あなたの月額会費は<strong>無料</strong>になります（<?= $__min ?>名を下回ると翌月から通常額に戻ります）。
            さらにその<?= $__min ?>名が<strong>それぞれ<?= $__min ?>名ずつ</strong>ご紹介くださると、<strong>以後ずっと無料</strong>です。詳しくは
            <a href="/pricing">料金と紹介特典の詳しい説明</a> をご覧ください。</p>
    <?php endif; ?>
</div>

<p class="muted" style="margin-top:10px;"><a href="/member/logout">ログアウト</a></p>
<?php require __DIR__ . '/_footer.php'; ?>
