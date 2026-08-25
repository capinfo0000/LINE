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
// URL は拡張子なし（members など）で出しているが、SCRIPT_NAME には実体の .php が
// 入るので、比較のためにここで落としておく（どちらの形でも一致する）。
$current = preg_replace('/\.php$/', '', basename($_SERVER['SCRIPT_NAME'] ?? ''));

/** ナビ項目（active 判定用に対象スクリプト名の配列を持つ）。 */
$navItems = [
    ['dashboard',     '', 'ダッシュボード', ['dashboard']],
    ['members',       '', '会員管理',       ['members', 'member_detail']],
    ['slots',         '', '説明会',         ['slots']],
    ['line_send',     '', 'LINE配信',       ['line_send']],
    ['contacts',      '', '申し込み者',     ['contacts']],
    ['openchat',      '', 'オープンチャット', ['openchat']],
    ['tags',          '', 'タグ管理',       ['tags']],
    ['announcements', '', 'お知らせ',       ['announcements']],
    ['feedback',      '', '意見箱' . (feedback_open_count() > 0 ? '（' . feedback_open_count() . '）' : ''), ['feedback']],
    ['account',       '', 'アカウント設定', ['account']],
];
if ((int) ($tenant['is_admin'] ?? 0) === 1) {
    // 公開される法的文書（規約・ポリシー・特商法の表記）に関わる設定は管理者のみ。
    $navItems[] = ['settings_site', '', '各種設定', ['settings_site']];
    $navItems[] = ['settings_legal', '', '規約・ポリシー', ['settings_legal']];
    $navItems[] = ['invites', '', '運営者を招待', ['invites']];
    // 初期設定ページは存在する間だけ表示（自己削除後は自動的に消える）。
    if (is_file(__DIR__ . '/settings_env.php')) {
        $navItems[] = ['settings_env', '', '初期設定', ['settings_env']];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle !== '' ? $pageTitle . ' - ' : '') ?>Enlink 運営</title>
    <?php echo page_meta_tags(['title' => $pageTitle, 'noindex' => true]); ?>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icon-32.png">
    <link rel="apple-touch-icon" href="/assets/icon-180.png">
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
            <a href="logout">ログアウト</a>
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
                <strong><?= $__w['level'] === 'critical' ? '重大なセキュリティ警告' : 'セキュリティ警告' ?>:</strong>
                <?= e($__w['msg']) ?>
            </div>
        <?php endforeach; ?>
