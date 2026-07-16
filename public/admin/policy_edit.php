<?php

/**
 * キャンセル・返金ポリシーの編集（ログイン中テナント専用）。
 * 主催者ごとに文面を設定でき、参加者にはそのイベント主催者のポリシーが表示される。
 * 空で保存すると既定の文面が使われる。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $text = (string) ($_POST['cancel_policy'] ?? '');
    $text = mb_substr($text, 0, 5000);
    set_tenant_cancel_policy($tenant['id'], $text);
    $tenant = find_tenant_by_id($tenant['id']);
    $msg = trim($text) === '' ? 'ポリシーを既定の文面に戻しました。' : 'キャンセルポリシーを保存しました。';
}

$policyText = (string) ($tenant['cancel_policy'] ?? '');
$sample = "本イベントは事前決済（前払い）制です。お支払い後のキャンセルは、以下の規定を適用します。\n\n"
    . "【返金率（開催日基準）】\n"
    . "・開催8日前まで：全額返金（決済手数料を除く）\n"
    . "・開催7〜2日前：50%返金\n"
    . "・開催前日・当日／無連絡不参加：返金なし\n\n"
    . "※ 返金はStripeを通じて、お支払いに使用されたカードへ行います。決済手数料は返金されません。\n"
    . "※ 主催者都合での中止（荒天等）の場合は全額返金します。";

$token = csrf_token();
$pageTitle = 'キャンセルポリシー';
$pageSub = '参加者に表示される返金・キャンセル規定を設定します';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?>
    <div class="flash flash--ok"><?= e($msg) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card__title">ポリシー本文</div>
    <p class="muted">この文面が、参加者の申込ページ等の「キャンセル・返金ポリシー」に表示されます。<strong>空のまま保存すると既定の文面</strong>が使われます。</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <textarea name="cancel_policy" rows="12" maxlength="5000" placeholder="<?= e($sample) ?>"><?= e($policyText) ?></textarea>
        <p class="hint">改行はそのまま反映されます。HTMLタグは使えません（安全のため自動でエスケープされます）。</p>
        <p style="margin-top:14px;">
            <button type="submit" class="btn">保存する</button>
            <a class="btn btn--ghost" href="../policy.php?t=<?= e(urlencode($tenant['id'])) ?>" target="_blank">プレビュー（公開ページ）</a>
        </p>
    </form>
</div>

<div class="card">
    <div class="card__title">記入例（コピーして編集できます）</div>
    <pre style="white-space:pre-wrap; background:#f3f4f6; padding:14px; border-radius:8px; font-size:.86rem; line-height:1.8;"><?= e($sample) ?></pre>
    <p class="hint">※ 返金額は主催者が参加者管理画面で個別に実行します（自動計算ではありません）。上記はあくまで参加者への案内文です。</p>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
