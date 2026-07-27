<?php

/**
 * キャンセル・返金ポリシー表示ページ（入会金＝買い切り一回払い）。
 * ※ 最終的な法務文面は Phase 8 で確定する。ここは表示土台。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>キャンセル・返金ポリシー</title>
    <style nonce="<?= e(csp_nonce()) ?>">
        body { font-family: system-ui, -apple-system, "Hiragino Kaku Gothic ProN", Meiryo, sans-serif;
               line-height: 1.8; color: #1f2937; max-width: 680px; margin: 0 auto; padding: 24px; background: #f9fafb; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px 28px; }
        h1 { font-size: 1.4rem; }
        a { color: #2563eb; }
        .muted { color: #6b7280; font-size: .9rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>キャンセル・返金ポリシー</h1>
        <p>本サービスの入会金は<strong>買い切り（一回払い・月額なし）</strong>です。デジタルサービスの性質上、
           入金後のご返金は原則としてお受けできません。</p>

        <h2>入会金について</h2>
        <p>入会金のお支払いをもって会員資格が付与され、会員専用サイト（人脈ディレクトリ・マッチング）を
           ご利用いただけます。会員資格は永続で、月額料金は発生しません。</p>

        <h2>お支払い・カード情報の取り扱い</h2>
        <p>カード情報の入力・処理は決済代行サービス Stripe 上で安全に行われます。<strong>当方は、カード番号・
           有効期限・セキュリティコードなどの決済情報を一切受け取らず、保管・閲覧もできません。</strong></p>

        <p class="muted" style="margin-top:24px;">※ 本ページの記載は暫定です。正式な返金規定・特商法表記は別途定めます。</p>
        <p style="margin-top:16px;"><a href="/">← トップへ戻る</a></p>
    </div>
</body>
</html>
