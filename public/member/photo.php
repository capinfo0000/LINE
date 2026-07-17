<?php

/**
 * 顔写真の配信（認可付き）。公開領域外の data/uploads/ から会員のみに配信する。
 *
 * - ?id=<member_id> 指定：その会員の写真（承認済みのみ）を配信。ディレクトリ表示用（Phase 5）。
 * - 指定なし：ログイン会員自身の写真を配信（編集画面プレビュー用。承認状態を問わない）。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$viewer = require_member();

$targetId = (string) ($_GET['id'] ?? '');
if ($targetId === '' || $targetId === $viewer['id']) {
    // 自分の写真（未承認でも本人には見せる）
    $profile = get_profile($viewer['id']);
} else {
    // 他会員の写真は承認済みのみ配信
    $target = find_member_by_id($targetId);
    if ($target === null || !member_can_login($target)) {
        http_response_code(404);
        exit;
    }
    $profile = get_profile($targetId);
    if (($profile['photo_status'] ?? '') !== 'approved') {
        http_response_code(404);
        exit;
    }
}

$abs = member_photo_abs_path($profile);
if ($abs === null) {
    http_response_code(404);
    exit;
}

$info = @getimagesize($abs);
$mime = $info['mime'] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($abs));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($abs);
