<?php

/**
 * プライバシーポリシー。本文は管理画面「規約・ポリシー」で編集できる（未編集なら既定文）。
 * 事業者名・連絡先・入会前に取得する情報は差し込み語で埋まる。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$title = 'プライバシーポリシー';
require __DIR__ . '/_legal_header.php';
?>
<?= legal_body_html('privacy') ?>

<?php $__note = site_setting('privacy_note'); ?>
<?php if (trim($__note) !== ''): ?>
<h2>補足</h2>
<p><?= nl2br(e($__note)) ?></p>
<?php endif; ?>
<?php if (legal_biz_incomplete()): ?>
<p class="muted">※ 事業者情報が未設定です。管理画面の「各種設定」から入力してください。</p>
<?php endif; ?>
<?php require __DIR__ . '/_legal_footer.php'; ?>
