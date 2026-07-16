<?php

/**
 * 決済成功ページ。Stripe から戻ってきた session_id を使って
 * 支払い結果を確認し、申込完了を表示する。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$sessionId = (string)($_GET['session_id'] ?? '');
$eventId = (string)($_GET['event_id'] ?? '');
$paid = false;
$eventName = '';
$amountText = '';
$email = '';

// セッションはイベント所有者の Stripe 上にある。所有者の画面登録鍵→Connect→プラットフォームで文脈確立。
$event = $eventId !== '' ? find_event($eventId) : null;
$account = $event !== null ? stripe_resolve_event($event) : null;

// 正規の遷移は「実在イベント＋Stripe文脈あり」。細工された値での 500 を避け、graceful に表示する。
if ($sessionId !== '' && $event !== null && stripe_ready_for_event($event)) {
    init_stripe();
    try {
        $session = \Stripe\Checkout\Session::retrieve($sessionId, stripe_opts($account));
        $paid = ($session->payment_status === 'paid');
        $eventName = $session->metadata['event_name'] ?? '';
        $email = $session->customer_details->email ?? '';
        if ($session->amount_total !== null) {
            $amountText = format_amount((int)$session->amount_total, (string)$session->currency);
        }
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('Session 取得失敗: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>申込完了</title>
    <link rel="stylesheet" href="/assets/app.css">
    <style nonce="<?= e(csp_nonce()) ?>">
        .ok { color: #16a34a; font-size: 1.4rem; font-weight: 800; margin: 0 0 8px; }
        .ng { color: var(--dng); font-size: 1.3rem; font-weight: 800; margin: 0 0 8px; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 8px 16px; margin: 16px 0; }
        dt { color: var(--muted); }
    </style>
</head>
<body>
<div class="container">
    <div class="brandbar">イベント参加申込</div>
    <div class="card">
        <?php if ($paid): ?>
            <p class="ok">✅ お申し込みが完了しました</p>
            <p>ご参加ありがとうございます。お支払いを確認しました。確認メールが Stripe より送信されます。</p>
            <p class="muted">※ カード情報は Stripe が直接お預かりしており、主催者（当方）は決済情報を受け取っておりません。</p>
            <dl>
                <?php if ($eventName !== ''): ?><dt>イベント</dt><dd><?= e($eventName) ?></dd><?php endif; ?>
                <?php if ($amountText !== ''): ?><dt>お支払い額</dt><dd><?= e($amountText) ?></dd><?php endif; ?>
            </dl>
            <?php if ($email !== ''): ?><p class="muted">確認メールを <?= e(mask_email_for_log($email)) ?> 宛にお送りします。</p><?php endif; ?>
        <?php else: ?>
            <p class="ng">お支払いを確認できませんでした</p>
            <p>恐れ入りますが、もう一度お試しいただくか、主催者までご連絡ください。</p>
        <?php endif; ?>
        <p style="margin-top:20px;"><a href="index.php">← トップへ戻る</a></p>
    </div>
</div>
</body>
</html>
