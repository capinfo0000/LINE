<?php

/**
 * 会員サイト共通ヘッダ（中央寄せの狭いカード）。
 * ページ側で $pageTitle（任意）／$showLogout（ログイン済みページで true）を設定して require する。
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? '';
$showLogout = $showLogout ?? false;
$wide = $wide ?? false;
$appWide = $appWide ?? false; // true でPC時に広い一覧レイアウト（さがす等）
$hideBrand = $hideBrand ?? false; // true で上部の「Enlink」ブランドバーを非表示
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle !== '' ? $pageTitle . ' - ' : '') ?>Enlink 会員サイト</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
<div class="container<?= $wide ? '' : ' container--narrow' ?><?= $appWide ? ' container--app' : '' ?>">
    <?php if (!$hideBrand): ?>
    <div class="brandbar">Enlink<?php if ($showLogout): ?><a href="/member/logout" style="float:right;font-size:.8rem;">ログアウト</a><?php endif; ?></div>
    <?php endif; ?>
