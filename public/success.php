<?php

/**
 * 決済完了ページ。会員化＋ID/PW発行は Webhook（非同期）で行われるため、
 * ここでは受付完了の案内のみ表示する（資格情報はメール／LINEで届く）。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>お支払い完了 - Enlink</title>
    <?php echo page_meta_tags(['title' => 'お支払い完了', 'noindex' => true]); ?>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icon-32.png">
    <link rel="apple-touch-icon" href="/assets/icon-180.png">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="container container--narrow">
    <div class="brandbar">Enlink</div>
    <h1>お支払いありがとうございます</h1>
    <div class="card">
        <p>ご入会手続きが完了しました。</p>
        <p>会員サイトの<strong>ログインID・仮パスワード</strong>を、メール（または公式LINE）でお送りします。
           初回ログイン時に、ご自身のパスワードへの変更をお願いします。</p>
        <p style="margin-top:16px;"><a class="btn" href="/member/login">会員ログインへ</a></p>
    </div>
    <p class="muted">数分待ってもご連絡が届かない場合は、運営までお問い合わせください。</p>
</div>
</body>
</html>
