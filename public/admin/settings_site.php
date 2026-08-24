<?php

/**
 * 各種設定：特定商取引法の表記・ポリシー文面などを管理画面から編集する。
 * 保存値は app_settings（site_* キー）に入り、公開ページ（tokushoho/policy/privacy/terms）へ反映される。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $n = site_setting_save($_POST);
    $msg = "設定を保存しました（{$n}項目）。公開ページに反映されます。";
}

$defs = site_setting_defs();
$token = csrf_token();
$pageTitle = '各種設定';
$pageSub = '特定商取引法の表記・ポリシー文面';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
    <div class="card__title">公開ページへの反映</div>
    <p class="hint" style="margin:0;">
        ここで入力した内容は、以下の公開ページに自動反映されます。<br>
        <a href="/tokushoho.php" target="_blank" rel="noopener">特定商取引法に基づく表記</a> ／
        <a href="/policy.php" target="_blank" rel="noopener">キャンセル・返金ポリシー</a> ／
        <a href="/privacy.php" target="_blank" rel="noopener">プライバシーポリシー</a> ／
        <a href="/terms.php" target="_blank" rel="noopener">利用規約</a>
    </p>
</div>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <div class="card">
        <div class="card__title">事業者情報（特定商取引法の表記）</div>
        <?php foreach (['biz_name', 'biz_owner', 'biz_address', 'biz_email', 'biz_tel'] as $k): ?>
            <label><?= e($defs[$k]['label']) ?></label>
            <input type="text" name="<?= e($k) ?>" value="<?= e(site_setting($k)) ?>" maxlength="300">
            <?php if ($defs[$k]['hint'] !== ''): ?><p class="hint" style="margin:4px 0 0;"><?= e($defs[$k]['hint']) ?></p><?php endif; ?>
        <?php endforeach; ?>
        <p class="hint" style="margin-top:12px;">未入力の項目は、公開ページでは「［氏名］」のようなプレースホルダーではなく空欄（または既定文）で表示されます。</p>
    </div>

    <div class="card">
        <div class="card__title">価格・ポリシーの文面</div>
        <label><?= e($defs['line_official_url']['label']) ?></label>
        <input type="text" name="line_official_url" value="<?= e(site_setting('line_official_url')) ?>" maxlength="300" placeholder="https://line.me/R/ti/p/@xxxxxxx">
        <p class="hint" style="margin:4px 0 0;"><?= e($defs['line_official_url']['hint']) ?></p>

        <?php foreach (['price_note', 'cancel_note', 'privacy_note', 'terms_note'] as $k): ?>
            <label><?= e($defs[$k]['label']) ?></label>
            <textarea name="<?= e($k) ?>" rows="5" maxlength="8000"><?= e(site_setting($k)) ?></textarea>
            <?php if ($defs[$k]['hint'] !== ''): ?><p class="hint" style="margin:4px 0 0;"><?= e($defs[$k]['hint']) ?></p><?php endif; ?>
        <?php endforeach; ?>
    </div>

    <p><button type="submit" class="btn">設定を保存</button></p>
</form>
<?php require __DIR__ . '/_app_footer.php'; ?>
