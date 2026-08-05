<?php

/**
 * 月額会費の登録（料金フェーズ）。
 * ログイン中の会員が月額500円のサブスクに登録する。未登録だと会員機能はロックされる。
 * すでに有効なら会員トップへ戻す。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member(false, true); // 未サブスクでも開ける
$memberId = (string) $member['id'];

// すでにサブスク有効、または無料フェーズなら会員トップへ。
if (!member_requires_subscription($member)) {
    header('Location: /member/dashboard.php');
    exit;
}

$priceId = (string) (env('STRIPE_PRICE_ID') ?? '');
$monthlyAmount = (int) env('MONTHLY_FEE_AMOUNT', '0');
$error = '';

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
            $params = [
                'mode' => 'subscription',
                'line_items' => [['quantity' => 1, 'price' => $priceId]],
                'metadata' => $metadata,
                'subscription_data' => ['metadata' => $metadata],
                'success_url' => base_url() . '/member/dashboard.php?msg=' . rawurlencode('月額登録が完了しました。') . '&type=ok',
                'cancel_url' => base_url() . '/member/subscribe.php',
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
    <p>Enlink は会員数が一定に達したため、<strong>月額会費（サブスクリプション）</strong><?php if ($monthlyAmount > 0): ?> <strong><?= e(format_amount($monthlyAmount)) ?> / 月</strong><?php endif; ?>のご登録が必要になりました。</p>
    <p class="muted">ご登録いただくと、これまでどおり会員機能（ディレクトリ・おすすめ・プロフィール等）をご利用いただけます。いつでも解約できます。</p>
    <form method="post" style="margin-top:14px;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <button type="submit" class="btn">月額会費を登録する（Stripe）</button>
    </form>
    <p class="muted" style="margin-top:12px;">カード情報の入力・処理は Stripe 上で安全に行われます。当方はカード情報を保持しません。</p>
</div>

<div class="card">
    <p class="muted" style="margin:0;">ご紹介で<strong>アクティブな有料会員を5名</strong>ご紹介いただくと、月額会費が無料になります。詳しくは
        <a href="/member/points.php">ポイント・紹介</a> をご覧ください。</p>
</div>

<p class="muted" style="margin-top:10px;"><a href="/member/logout.php">ログアウト</a></p>
<?php require __DIR__ . '/_footer.php'; ?>
