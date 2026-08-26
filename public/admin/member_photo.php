<?php

/**
 * 運営用：会員の顔写真配信（承認前でも運営は閲覧可）。公開領域外から配信する。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

require_tenant();

$id = (string) ($_GET['id'] ?? '');
$profile = $id !== '' ? get_profile($id) : null;
$abs = $profile !== null ? member_photo_abs_path($profile) : null;
if ($abs === null) {
    http_response_code(404);
    exit;
}
$info = @getimagesize($abs);
header('Content-Type: ' . ($info['mime'] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($abs));
header('Cache-Control: private, max-age=60');
header('X-Content-Type-Options: nosniff');
readfile($abs);
