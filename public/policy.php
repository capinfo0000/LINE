<?php

/**
 * キャンセル・返金ポリシー。本文は管理画面「規約・ポリシー」で編集できる（未編集なら既定文）。
 * 料金の記述は差し込み語で埋まるため、無料フェーズ／課金フェーズの切替に追従する。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$title = 'キャンセル・返金ポリシー';
require __DIR__ . '/_legal_header.php';
?>
<?= legal_body_html('policy') ?>

<?php $__cancel = site_setting('cancel_note'); ?>
<?php if (trim($__cancel) !== ''): ?>
<h2>補足</h2>
<p><?= nl2br(e($__cancel)) ?></p>
<?php endif; ?>
<?php require __DIR__ . '/_legal_footer.php'; ?>
