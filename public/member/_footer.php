<?php
// 会員サイトの下部2タブバー（さがす／プロフィール）。
// 主要ページのみ表示。プロフィール詳細(member_view)は独自のアクションバーがあるので出さない。
$__cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__tabPages = ['directory.php', 'profile.php', 'dashboard.php', 'points.php', 'billing.php', 'recommend.php'];
$__showTabs = in_array($__cur, $__tabPages, true);
$__onSearch = in_array($__cur, ['directory.php', 'recommend.php'], true);
$__onProfile = in_array($__cur, ['profile.php', 'dashboard.php', 'points.php', 'billing.php'], true);
?>
<?php if ($__showTabs): ?><div class="tp-tabspacer"></div><?php endif; ?>
</div>
<?php if ($__showTabs): ?>
<nav class="tp-tabbar" aria-label="メニュー">
    <div class="tp-tabbar__inner">
        <a href="/member/directory.php" class="<?= $__onSearch ? 'on' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            さがす
        </a>
        <a href="/member/dashboard.php" class="<?= $__onProfile ? 'on' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg>
            プロフィール
        </a>
    </div>
</nav>
<?php endif; ?>
</body>
</html>
