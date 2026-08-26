<?php

/**
 * 決済中断ページ。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>お支払いの中断 - Enlink</title>
    <?php echo page_meta_tags(['title' => 'お支払いの中断', 'noindex' => true]); ?>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icon-32.png">
    <link rel="apple-touch-icon" href="/assets/icon-180.png">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="container container--narrow">
    <div class="brandbar">Enlink</div>
    <h1>お支払いは完了していません</h1>
    <div class="card">
        <p>お支払いがキャンセルされました。もう一度お手続きいただけます。</p>
        <p style="margin-top:16px;"><a class="btn" href="/member/subscribe">月額会費のお申し込みへ戻る</a></p>
        <p class="muted" style="margin-top:12px;"><a href="/member/dashboard">← 会員トップへ</a></p>
    </div>
</div>
</body>
</html>
