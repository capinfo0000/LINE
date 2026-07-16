<?php

/**
 * キャンセル・返金ポリシー表示ページ。
 * 文面は下の HTML を直接編集してください（前払い運用の要となる規定です）。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

// 表示対象の主催者を特定（event_id または t=tenant_id）。その主催者が設定した文面を優先表示する。
$eventId = (string) ($_GET['event_id'] ?? '');
$t = (string) ($_GET['t'] ?? '');
$owner = null;
if ($eventId !== '') {
    $ev = find_event($eventId);
    if ($ev !== null) {
        $owner = find_tenant_by_id((string) $ev['tenant_id']);
    }
} elseif ($t !== '') {
    $owner = find_tenant_by_id($t);
}
$customPolicy = ($owner !== null && trim((string) ($owner['cancel_policy'] ?? '')) !== '')
    ? (string) $owner['cancel_policy']
    : null;
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
        table { border-collapse: collapse; width: 100%; margin: 16px 0; }
        th, td { border: 1px solid #e5e7eb; padding: 8px 12px; text-align: left; }
        th { background: #f3f4f6; }
        a { color: #2563eb; }
        .muted { color: #6b7280; font-size: .9rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>キャンセル・返金ポリシー</h1>
        <?php if ($customPolicy !== null): ?>
            <p><?= nl2br(e($customPolicy)) ?></p>
        <?php else: ?>
            <p>本イベントは<strong>事前決済（前払い）</strong>制です。お支払い後のキャンセルについては、以下の返金規定を適用します。</p>

            <h2>返金率（開催日基準）</h2>
            <table>
                <tr><th>キャンセル時期</th><th>返金額</th></tr>
                <tr><td>開催 8 日前まで</td><td>全額返金（決済手数料を除く）</td></tr>
                <tr><td>開催 7〜2 日前</td><td>50% 返金</td></tr>
                <tr><td>開催前日・当日／無連絡不参加</td><td>返金なし</td></tr>
            </table>

            <p class="muted">※ 返金は Stripe を通じて、お支払いに使用されたカードへ行います。決済手数料は返金されません。<br>
               ※ 主催者都合での中止（荒天等）の場合は全額返金します。</p>
        <?php endif; ?>

        <h2>お支払い・カード情報の取り扱い</h2>
        <p>カード情報の入力・処理は決済代行サービス Stripe 上で安全に行われます。<strong>主催者（当方）は、カード番号・有効期限・セキュリティコードなどの決済情報を一切受け取らず、保管・閲覧もできません。</strong>主催者が Stripe の管理画面で確認できるのは、お名前・連絡先・お支払い状況・返金処理に必要な情報に限られます。</p>

        <p style="margin-top:24px;"><a href="index.php">← イベント一覧へ戻る</a></p>
    </div>
</body>
</html>
