<?php

/**
 * 当日支払いの申込完了ページ。
 * 決済は発生していない。当日に会場で支払う金額を案内する。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$eventId = (string) ($_GET['event_id'] ?? '');
$event = $eventId !== '' ? find_event($eventId) : null;
$partySize = max(1, (int) ($_GET['party_size'] ?? 1));
$total = max(0, (int) ($_GET['total'] ?? 0));
$currency = $event['currency'] ?? 'jpy';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>申込完了（当日支払い）</title>
    <link rel="stylesheet" href="/assets/app.css">
    <style nonce="<?= e(csp_nonce()) ?>">
        .ok { color: #16a34a; font-size: 1.4rem; font-weight: 800; margin: 0 0 8px; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 8px 16px; margin: 16px 0; }
        dt { color: var(--muted); }
    </style>
</head>
<body>
<div class="container">
    <div class="brandbar">イベント参加申込</div>
    <div class="card">
        <p class="ok">✅ お申し込みを受け付けました</p>
        <p>当日、会場で参加費をお支払いください。<strong>今回はまだお支払いは発生していません。</strong></p>
        <dl>
            <?php if ($event !== null): ?><dt>イベント</dt><dd><?= e($event['name'] ?? '') ?></dd><?php endif; ?>
            <?php if ($event !== null): ?><dt>日時・場所</dt><dd><?= e(($event['date'] ?? '') . '　' . ($event['place'] ?? '')) ?></dd><?php endif; ?>
            <dt>参加人数</dt><dd><?= $partySize ?> 名</dd>
            <dt>当日お支払い額</dt><dd><span class="total" style="margin:0;"><?= e(format_amount($total, $currency)) ?></span></dd>
        </dl>
        <p class="muted">※ ご都合が悪くなった場合は、お手数ですが主催者までご連絡ください。</p>
        <p style="margin-top:20px;"><a href="index.php">← トップへ戻る</a></p>
    </div>
</div>
</body>
</html>
