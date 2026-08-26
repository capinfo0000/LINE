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

// 初回請求を課金開始日（到達した月の翌月1日）まで先送りできるか。
// Stripe は trial_end が「およそ48時間より先」でないと受け付けないため、
// 月末ぎりぎりに到達して猶予が短いときは先送りせず、画面の案内も出し分ける。
$billingStartsAt = billing_starts_at();
$trialEnd = ($billingStartsAt !== null && $billingStartsAt > time() + 172800) ? $billingStartsAt : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($priceId === '') {
        $error = '現在お申し込みを受け付けられません（設定未完了）。運営にお問い合わせください。';
    } elseif (!rate_limit_check('subscribe', 10, 3600)) {
        $error = '試行が多すぎます。しばらく時間をおいてお試しください。';
    } else {
        try {
            init_stripe();
            $metadata = ['purpose' => 'subscription', 'member_id' => $memberId];
            $email = (string) ($member['email'] ?? '');
            if ($email !== '') {
                $metadata['email'] = $email;
            }
            $subscriptionData = ['metadata' => $metadata];
            // 猶予期間中に申し込んだ人から先に請求しないよう、初回請求日を課金開始日に揃える。
            // これが無いと「猶予期間は無料」と案内しながら、早く決めた人ほど多く払うことになる。
            if ($trialEnd !== null) {
                $subscriptionData['trial_end'] = $trialEnd;
            }
            $params = [
                'mode' => 'subscription',
                'line_items' => [['quantity' => 1, 'price' => $priceId]],
                'metadata' => $metadata,
                'subscription_data' => $subscriptionData,
            ];
            // 紹介の条件を満たしていれば、最初の請求から無料にする。
            // ここで割引を付けないと、定期実行の判定が回るまでの1か月分だけ
            // 請求されてしまう（「5人紹介したら無料」と案内しているのに、
            // 初月だけ取られる形になる）。
            $waiverCoupon = member_qualifies_for_waiver($member) ? waiver_coupon_id() : '';
            if ($waiverCoupon !== '') {
                $params['discounts'] = [['coupon' => $waiverCoupon]];
            }
            $params += [
                'success_url' => base_url() . '/member/dashboard?msg=' . rawurlencode('月額登録が完了しました。') . '&type=ok',
                'cancel_url' => base_url() . '/member/subscribe',
            ];
            if ($email !== '') {
                $params['customer_email'] = $email;
            }
            if (!empty($member['stripe_customer_id'])) {
                $params['customer'] = (string) $member['stripe_customer_id'];
                unset($params['customer_email']);
            }
            $session = \Stripe\Checkout\Session::create($params);
            header('Location: ' . $session->url);
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
            <a href="/member/points">ポイント・紹介</a> をご覧ください。</p>
    <?php endif; ?>
</div>

<p class="muted" style="margin-top:10px;"><a href="/member/logout">ログアウト</a></p>
<?php require __DIR__ . '/_footer.php'; ?>
