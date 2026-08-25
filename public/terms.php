<?php

/**
 * 利用規約。本文は管理画面「規約・ポリシー」で編集できる（未編集なら既定文）。
 * 事業者名や入会の流れ・料金の記述は差し込み語で埋まるため、各種設定や
 * 運用モードのON/OFFを切り替えると本文も追従する。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$title = '利用規約';
require __DIR__ . '/_legal_header.php';
?>
<?= legal_body_html('terms') ?>

<?php $__note = site_setting('terms_note'); ?>
<?php if (trim($__note) !== ''): ?>
<h2>補足</h2>
<p><?= nl2br(e($__note)) ?></p>
<?php endif; ?>
<?php if (legal_biz_incomplete()): ?>
<p class="muted">※ 事業者情報が未設定です。管理画面の「各種設定」から入力してください。</p>
<?php endif; ?>
<?php require __DIR__ . '/_legal_footer.php'; ?>
