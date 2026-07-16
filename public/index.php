<?php

/**
 * トップ（ランディング）。
 * 参加者は主催者から受け取った「イベントごとの申込リンク」（apply.php?event_id=...）
 * または公開イベント一覧（o.php）から申し込みます。ここは案内と運営者ログインへの入口。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>イベント事前決済サービス</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="container">
    <div class="brandbar">イベント事前決済</div>
    <h1>イベント事前決済サービス</h1>
    <p class="muted">小規模イベントの参加費を、事前決済（前払い）または当日支払いで集められるサービスです。</p>

    <div class="card">
        <div class="card__title">参加者の方へ</div>
        <p>主催者から受け取った<strong>申込リンク</strong>から、各イベントにお申し込みください。</p>
        <p class="muted">カード情報の入力は決済代行 Stripe 上で行われ、主催者・当サービスは決済情報を保持しません。</p>
    </div>

    <div class="card">
        <div class="card__title">主催者の方へ</div>
        <p>イベントの作成・参加者管理・返金は主催者ページから行えます。</p>
        <p>
            <a class="btn" href="admin/signup.php">無料で新規登録</a>
            <a href="admin/login.php" style="margin-left:10px;">ログイン</a>
        </p>
        <p class="muted">メールアドレスとパスワードだけで、すぐに始められます。</p>
    </div>

    <p class="muted" style="margin-top:24px; border-top:1px solid var(--border); padding-top:14px;">
        <a href="policy.php">キャンセル・返金ポリシー</a> ／
        <a href="tokushoho.php">特定商取引法に基づく表記</a> ／
        <a href="terms.php">利用規約</a> ／
        <a href="privacy.php">プライバシーポリシー</a>
    </p>
</div>
</body>
</html>
