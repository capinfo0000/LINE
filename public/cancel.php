<?php

/**
 * 決済中断ページ。参加者が Stripe の決済画面で「戻る」を押した場合に表示。
 * （まだ支払いは発生していません）
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$eventId = (string)($_GET['event_id'] ?? '');
$event = $eventId !== '' ? find_event($eventId) : null;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>お支払いは完了していません</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="container">
    <div class="brandbar">イベント参加申込</div>
    <div class="card">
        <h1 style="font-size:1.2rem;">お支払いは完了していません</h1>
        <p class="muted">料金は請求されていません。もう一度お申し込みいただけます。</p>
        <?php if ($event !== null): ?>
            <form action="apply.php" method="get" style="margin-top:14px;">
                <input type="hidden" name="event_id" value="<?= e($event['id']) ?>">
                <button type="submit" class="btn">「<?= e($event['name'] ?? '') ?>」をもう一度申し込む</button>
            </form>
        <?php endif; ?>
        <p style="margin-top:20px;"><a href="index.php">← トップへ戻る</a></p>
    </div>
</div>
</body>
</html>
