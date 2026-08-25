<?php

/** 法務ページ共通ヘッダ。$title を設定してから require する。 */
declare(strict_types=1);
$title = $title ?? '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?></title>
    <?php echo page_meta_tags(['title' => $title, 'type' => 'article']); ?>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
    <style nonce="<?= e(csp_nonce()) ?>">
        .legal { line-height: 1.9; }
        .legal h2 { font-size: 1.05rem; margin-top: 24px; }
        .legal th { white-space: nowrap; width: 30%; }
        .legal ol { padding-left: 1.2em; }
        .legal .back { margin-top: 24px; }
    </style>
</head>
<body>
<div class="container legal">
    <div class="brandbar">Enlink</div>
    <div class="card">
        <h1><?= e($title) ?></h1>
