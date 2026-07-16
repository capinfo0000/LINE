<?php

/**
 * トップ（ランディング）。
 * 会員は入金後に発行される ID/PW で会員サイトにログインする（会員エリアは Phase 1 で追加）。
 * ここは案内と、運営者ログインへの入口。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AKマッチング</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="container">
    <div class="brandbar">AKマッチング</div>
    <h1>AKマッチング</h1>
    <p class="muted">会員制の人脈マッチングサービスです。会員は専用サイトでプロフィールを登録し、
       条件に合う相手を検索・おすすめから見つけてつながれます。</p>

    <div class="card">
        <div class="card__title">会員の方へ</div>
        <p>入会手続き完了後にお渡しした<strong>ログインID・パスワード</strong>で会員サイトにログインしてください。</p>
        <p><a class="btn" href="/member/login.php">会員ログイン</a></p>
    </div>

    <div class="card">
        <div class="card__title">入会をご検討の方へ</div>
        <p>公式LINEの案内に沿って、説明会・個別面談を経てご入会いただけます。</p>
    </div>

    <div class="card">
        <div class="card__title">運営の方へ</div>
        <p><a class="btn" href="admin/login.php">運営ログイン</a></p>
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
