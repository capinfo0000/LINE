<?php
// 会員サイトの下部2タブバー（さがす／プロフィール）。
// 主要ページのみ表示。プロフィール詳細(member_view)は独自のアクションバーがあるので出さない。
// URL は拡張子なし（/member/directory）で出しているが、SCRIPT_NAME には
// 実体の .php が入るので、比較のためにここで落としておく（どちらの形でも一致する）。
$__cur = preg_replace('/\.php$/', '', basename($_SERVER['SCRIPT_NAME'] ?? ''));
$__tabPages = ['directory', 'profile', 'dashboard', 'points', 'billing', 'recommend'];
$__showTabs = in_array($__cur, $__tabPages, true);
$__onSearch = in_array($__cur, ['directory', 'recommend'], true);
$__onProfile = in_array($__cur, ['profile', 'dashboard', 'points', 'billing'], true);
?>
<?php
// 一番下の広告帯。タブバーが出る画面だけに置く（タブバーが無い画面で
// 下に張り付いていても、何の上に乗っているのか分からなくなるため）。
$__adBar = $__showTabs ? ads_render_each('bar', 6) : [];
?>
<?php if ($__showTabs): ?><div class="tp-tabspacer<?= $__adBar !== [] ? ' tp-tabspacer--ad' : '' ?>"></div><?php endif; ?>
</div>
<?php if ($__showTabs): ?>
<?php // 広告帯とタブバーを1つの固定コンテナに入れる。こうすると広告の高さが
      // 変わってもタブバーとの位置関係がずれない（高さを数値で決め打ちしなくて済む）。 ?>
<div class="tp-bottom">
<?php if ($__adBar !== []): ?>
    <div class="ad-bar">
        <div class="ad-bar__rail"<?= count($__adBar) > 1 ? ' data-autoplay="4500"' : '' ?>>
            <?= implode('', $__adBar) ?>
        </div>
    </div>
<?php endif; ?>
<nav class="tp-tabbar" aria-label="メニュー">
    <div class="tp-tabbar__inner">
        <a href="/member/directory" class="<?= $__onSearch ? 'on' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            さがす
        </a>
        <a href="/member/dashboard" class="<?= $__onProfile ? 'on' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg>
            プロフィール
        </a>
    </div>
</nav>
</div>
<?php endif; ?>
</body>
</html>
