<?php

/**
 * サイトマップ（/sitemap.xml として配信）。
 *
 * ドメインは .env の APP_BASE_URL から作るため、静的ファイルにはできない
 * （ドメインを変えたときに古いURLが残ってしまう）。
 *
 * 載せるのは公開ページだけ。会員ページ・運営画面は noindex なので入れない。
 * ここに書いたURLと robots.txt / noindex の指定が矛盾しないよう、
 * 一覧は「索引に載せる意図があるページ」だけに保つ。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$base = rtrim(base_url(), '/');

// [パス, 更新頻度, 優先度]
$pages = [
    ['/',           'weekly',  '1.0'],
    ['/about',      'monthly', '0.9'],
    ['/terms',      'yearly',  '0.3'],
    ['/privacy',    'yearly',  '0.3'],
    ['/policy',     'yearly',  '0.3'],
    ['/tokushoho',  'yearly',  '0.3'],
];

// 最終更新日は、そのページの実体ファイルの更新時刻から出す。
// 手で書くと更新のたびに直す必要があり、必ず古くなるため。
$fileOf = [
    '/'          => __DIR__ . '/member/login.php',
    '/about'     => __DIR__ . '/about.php',
    '/terms'     => __DIR__ . '/terms.php',
    '/privacy'   => __DIR__ . '/privacy.php',
    '/policy'    => __DIR__ . '/policy.php',
    '/tokushoho' => __DIR__ . '/tokushoho.php',
];

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');  // サイトマップ自体は検索結果に出さない

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as [$path, $freq, $pri]) {
    $file = $fileOf[$path] ?? '';
    $mtime = ($file !== '' && is_file($file)) ? (int) filemtime($file) : time();
    echo "  <url>\n";
    echo '    <loc>' . e($base . $path) . "</loc>\n";
    echo '    <lastmod>' . date('Y-m-d', $mtime) . "</lastmod>\n";
    echo '    <changefreq>' . $freq . "</changefreq>\n";
    echo '    <priority>' . $pri . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
