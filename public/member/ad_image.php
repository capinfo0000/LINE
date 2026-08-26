<?php

/**
 * 広告画像の配信。公開領域の外（data/ads/）から会員にだけ渡す。
 *
 * 広告は会員画面にしか出ないので、ここも会員限定にしている。
 * URL に v=（更新時刻）が付くので、差し替えたときに古い画像が残らない。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

require_member();

$ad = find_ad((int) ($_GET['id'] ?? 0));
if ($ad === null) {
    http_response_code(404);
    exit;
}
$abs = ad_image_abs_path($ad);
if ($abs === null) {
    http_response_code(404);
    exit;
}

$info = @getimagesize($abs);
$mime = $info['mime'] ?? 'application/octet-stream';

clearstatcache(true, $abs);
$etag = '"' . substr(md5((string) filemtime($abs) . '-' . (string) filesize($abs)), 0, 20) . '"';
// v= が付いた URL は中身と1対1なので、長めに持たせてよい。
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($abs));
readfile($abs);
