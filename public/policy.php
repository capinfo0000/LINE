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
        <p>本サービスの会員資格は<strong>月額会費（サブスクリプション）</strong>によりご利用いただけます。
           デジタルサービスの性質上、お支払い済みの月額会費のご返金は原則としてお受けできません。</p>

        <h2>月額会費について</h2>
        <p>会員数が一定数に達するまでは無料でご利用いただけます。一定数を超えた以降は月額会費（税込500円）
           が必要となります。また、ご紹介の特典条件を満たす場合、月額会費が無料となることがあります。</p>

        <h2>解約について</h2>
        <p>会員サイトの「お支払い・解約の管理」から<strong>いつでも解約</strong>できます。解約すると次回以降の
           請求が停止します。現在の請求期間の終了まではご利用いただけますが、<strong>当月分の日割り返金は
           行いません</strong>。</p>

        <h2>お支払い・カード情報の取り扱い</h2>
        <p>カード情報の入力・処理は決済代行サービス Stripe 上で安全に行われます。<strong>当方は、カード番号・
           有効期限・セキュリティコードなどの決済情報を一切受け取らず、保管・閲覧もできません。</strong></p>

        <?php $__cancel = site_setting('cancel_note'); ?>
        <?php if (trim($__cancel) !== ''): ?>
        <p><?= nl2br(e($__cancel)) ?></p>
        <?php endif; ?>
        <p style="margin-top:16px;"><a href="/">← トップへ戻る</a></p>
    </div>
</body>
</html>
