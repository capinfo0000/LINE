<?php

/**
 * 月額会費の決済ページ（サブスクリプション）。
 *
 * オンボーディングでは公式LINE Bot（Phase 3）がこのページのリンクを送る。
 * GET: 金額とメールを確認する画面。POST: Stripe Checkout(mode=payment) を作成して遷移する。
 * カード情報は Stripe 上でのみ入力され、当サーバーは決済情報を保持しない。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$email = strtolower(trim((string) ($_GET['email'] ?? ($_POST['email'] ?? ''))));
$lineUserId = trim((string) ($_GET['lu'] ?? ($_POST['lu'] ?? '')));
$priceId = (string) (env('STRIPE_PRICE_ID') ?? '');
$monthlyAmount = (int) env('MONTHLY_FEE_AMOUNT', '0'); // 表示用（0なら金額非表示）。実際の金額はStripeのPriceが基準。
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    if ($priceId === '') {
        $error = '月額プラン（STRIPE_PRICE_ID）が未設定です。運営者にお問い合わせください。';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'メールアドレスの形式が正しくありません。';
    } elseif (empty($_POST['agree'])) {
        $error = '利用規約・プライバシーポリシー・特商法表記への同意が必要です。';
    } elseif (!rate_limit_check('checkout', 10, 3600)) {
        $error = '試行が多すぎます。しばらく時間をおいてお試しください。';
    } else {
        try {
            init_stripe();
            $metadata = ['purpose' => 'subscription'];
            if ($email !== '') {
                $metadata['email'] = $email;
            }
            if ($lineUserId !== '') {
                $metadata['line_user_id'] = $lineUserId;
            }
            $params = [
                'mode' => 'subscription',
                'line_items' => [[
                    'quantity' => 1,
                    'price' => $priceId, // Stripe側で作成した「継続(月額)Price」のID
                ]],
                'metadata' => $metadata,
                'subscription_data' => ['metadata' => $metadata],
                'success_url' => base_url() . '/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => base_url() . '/cancel',
            ];
            if ($email !== '') {
                $params['customer_email'] = $email;
            }
            $session = \Stripe\Checkout\Session::create($params);
            header('Location: ' . $session->url);
            exit;
        } catch (\Throwable $e) {
            error_log('checkout create error: ' . $e->getMessage());
            $error = '決済ページの作成に失敗しました。時間をおいて再度お試しください。';
        }
    }
}

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>月額会費のお申し込み - Enlink</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="container container--narrow">
    <div class="brandbar">Enlink</div>
    <h1>月額会費のお申し込み</h1>
    <?php if ($error !== ''): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
    <div class="card">
        <p>月額会費（サブスクリプション）<?php if ($monthlyAmount > 0): ?>：<strong><?= e(format_amount($monthlyAmount)) ?> / 月</strong><?php endif; ?></p>
        <p class="muted">毎月自動で更新されます。いつでも解約できます。お申し込み完了後、会員サイトのログイン情報をお送りします（初回ログイン時にパスワード変更をお願いします）。</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="lu" value="<?= e($lineUserId) ?>">
            <label>メールアドレス（ログイン情報の送付先）</label>
            <input type="email" name="email" value="<?= e($email) ?>" required autocomplete="email" placeholder="you@example.com">
            <p style="margin-top:12px;">
                <label style="font-weight:normal;">
                    <input type="checkbox" name="agree" value="1" required>
                    <a href="terms" target="_blank">利用規約</a>・<a href="privacy" target="_blank">プライバシーポリシー</a>・<a href="policy" target="_blank">返金ポリシー</a>に同意します
                </label>
            </p>
            <p style="margin-top:12px;"><button type="submit" class="btn">お支払いへ進む（Stripe）</button></p>
        </form>
    </div>
    <p class="muted">カード情報の入力・処理は Stripe 上で安全に行われます。当方はカード情報を保持しません。</p>
    <p class="muted"><a href="policy">キャンセル・返金ポリシー</a></p>
</div>
</body>
</html>
