<?php

/**
 * ログイン後の管理画面シェル（サイドバー＋トップバー）。
 * 使い方：ページ側で require_tenant() 済みの $tenant を用意し、
 *   $pageTitle / $pageSub / $topActions（任意）を設定してから require する。
 *   末尾で _app_footer.php を require して閉じる。
 */

declare(strict_types=1);

$pageTitle = $pageTitle ?? '';
$pageSub   = $pageSub ?? '';
$topActions = $topActions ?? '';
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

/** ナビ項目（active 判定用に対象スクリプト名の配列を持つ）。 */
$navItems = [
    ['dashboard.php', '', 'ダッシュボード', ['dashboard.php']],
    ['members.php',   '', '会員管理',       ['members.php', 'member_detail.php']],
    ['slots.php',     '', '説明会・面談',   ['slots.php']],
    ['broadcast.php', '', '一斉配信',       ['broadcast.php']],
    ['openchat.php',  '', 'オープンチャット', ['openchat.php']],
    ['tags.php',      '', 'タグ管理',       ['tags.php']],
    ['account.php',   '', 'アカウント設定', ['account.php']],
];
if ((int) ($tenant['is_admin'] ?? 0) === 1) {
    $navItems[] = ['invites.php', '', '運営者を招待', ['invites.php']];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle !== '' ? $pageTitle . ' - ' : '') ?>Enlink 運営</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sidebar__brand">Enlink</div>
        <nav class="nav">
            <?php foreach ($navItems as [$href, $icon, $label, $match]): ?>
                <a href="<?= e($href) ?>" class="<?= in_array($current, $match, true) ? 'active' : '' ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
            <div class="nav__sep"></div>
            <a href="logout.php">ログアウト</a>
        </nav>
        <div class="sidebar__foot"><?= e($tenant['display_name'] ?? '') ?><br><?= e($tenant['email'] ?? '') ?></div>
    </aside>
    <div class="content">
        <header class="topbar">
            <div>
                <h1 class="topbar__title"><?= e($pageTitle) ?></h1>
                <?php if ($pageSub !== ''): ?><p class="topbar__sub"><?= e($pageSub) ?></p><?php endif; ?>
            </div>
            <?php if ($topActions !== ''): ?><div class="topbar__actions"><?= $topActions ?></div><?php endif; ?>
        </header>
        <main class="page">
        <?php foreach (security_warnings() as $__w): ?>
            <div class="flash flash--ng">
                <strong><?= $__w['level'] === 'critical' ? '🔴 重大なセキュリティ警告' : '⚠️ セキュリティ警告' ?>:</strong>
                <?= e($__w['msg']) ?>
            </div>
        <?php endforeach; ?>
