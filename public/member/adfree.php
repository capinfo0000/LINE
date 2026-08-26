<?php

/**
 * 広告非表示の期間券を買う画面（追加料金）。
 *
 * 月額会費とは別会計で、買い切り（一回払い）にしている。月額サブスクにすると
 * Stripe の invoice.paid / customer.subscription.deleted が会費のサブスクと
 * 区別できず、「広告オプションを解約したら会員が停止される」事故が起きるため。
 * 買い切りなら checkout.session.completed だけで完結し、既存の課金処理に触れない。
 *
 * 価格（STRIPE_PRICE_ID_ADFREE）が未設定のうちは購入導線を出さない。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();
$memberId = (string) $member['id'];
$priceId = (string) (env('STRIPE_PRICE_ID_ADFREE', '') ?? '');
$days = adfree_purchase_days();
$error = '';
$msg = (string) ($_GET['msg'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($priceId === '') {
        $error = '現在お申し込みを受け付けていません。';
    } elseif (!rate_limit_check('adfree', 10, 3600)) {
        $error = '試行が多すぎます。しばらく時間をおいてお試しください。';
    } else {
        try {
            init_stripe();
            $metadata = ['purpose' => 'adfree', 'member_id' => $memberId, 'days' => (string) $days];
            $params = [
                'mode' => 'payment',
                'line_items' => [['quantity' => 1, 'price' => $priceId]],
                'metadata' => $metadata,
                // 支払い側にも同じ印を残す（Checkout の metadata だけだと
                // 後から突き合わせにくい場面があるため）。
                'payment_intent_data' => ['metadata' => $metadata],
                'success_url' => base_url() . '/member/adfree?msg=' . rawurlencode('お手続きが完了しました。反映まで少しお待ちください。'),
                'cancel_url' => base_url() . '/member/adfree',
            ];
            $email = (string) ($member['email'] ?? '');
            if (!empty($member['stripe_customer_id'])) {
                $params['customer'] = (string) $member['stripe_customer_id'];
            } elseif ($email !== '') {
                $params['customer_email'] = $email;
            }
            $session = \Stripe\Checkout\Session::create($params);
            header('Location: ' . $session->url);
            exit;
        } catch (\Throwable $e) {
            error_log('adfree checkout error: ' . $e->getMessage());
            $error = '決済ページの作成に失敗しました。時間をおいて再度お試しください。';
        }
    }
}

$until = (int) ($member['ads_free_until'] ?? 0);
$isFree = member_ads_free($member);
$token = csrf_token();
$pageTitle = '広告を非表示にする';
$showTabs = true;
require __DIR__ . '/_header.php';
?>
<h1 style="font-size:1.4rem;margin:0 0 4px;">広告を非表示にする</h1>
<p class="muted" style="margin:0 0 14px;"><a href="/member/dashboard">← マイページ</a></p>

<?php if ($msg !== ''): ?><div class="flash flash--ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash--ng"><?= e($error) ?></div><?php endif; ?>

<div class="card">
    <?php if ($isFree && $until > time()): ?>
        <div class="card__title" style="margin:0;">いま広告は表示されていません</div>
        <p style="margin:.6rem 0 0;">
            <strong><?= e(date('Y年n月j日', $until)) ?></strong> まで広告なしでご利用いただけます。
        </p>
        <p class="hint" style="margin:.6rem 0 0;">期限が切れる前でも、追加で購入すれば期間を延長できます（いまの期限に足されます）。</p>
    <?php elseif ($isFree): ?>
        <div class="card__title" style="margin:0;">いま広告は表示されていません</div>
        <p class="hint" style="margin:.6rem 0 0;">ご契約中のプランの特典として、広告を出していません。</p>
    <?php else: ?>
        <div class="card__title" style="margin:0;">広告について</div>
        <p style="margin:.6rem 0 0;">
            会員画面には広告が表示されます。追加料金をお支払いいただくと、
            <strong><?= (int) $days ?>日間</strong>のあいだ広告を出さずにご利用いただけます。
        </p>
    <?php endif; ?>
</div>

<?php if ($priceId !== ''): ?>
    <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <div class="card__title">
            <?= $until > time() ? '期間を延長する' : '広告を非表示にする' ?>
            <span class="badge badge--info"><?= (int) $days ?>日間</span>
        </div>
        <p class="hint" style="margin:.4rem 0 0;">
            お支払いは1回きりです（自動更新はありません）。金額はお支払い画面でご確認いただけます。
        </p>
        <p style="margin-top:14px;"><button type="submit" class="btn btn--lg">お支払いへ進む</button></p>
    </form>
<?php else: ?>
    <div class="card">
        <p class="hint" style="margin:0;">
            現在このお申し込みは受け付けていません。開始しましたら、あらためてご案内します。
        </p>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
