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

// kind: photo（顔写真・既定）/ cover（カバー画像）/ card（名刺画像）。
$kind = (string) ($_GET['kind'] ?? 'photo');
$col = ['photo' => 'photo_path', 'cover' => 'cover_path', 'card' => 'card_path'][$kind] ?? 'photo_path';

$targetId = (string) ($_GET['id'] ?? '');
if ($targetId === '' || $targetId === $viewer['id']) {
    // 自分の画像（未承認でも本人には見せる）
    $profile = get_profile($viewer['id']);
} else {
    $target = find_member_by_id($targetId);
    if ($target === null || !member_can_login($target)) {
        http_response_code(404);
        exit;
    }
    $profile = get_profile($targetId);
    // 顔写真は承認済みのみ配信。カバー・名刺は全会員に公開。
    if ($col === 'photo_path' && ($profile['photo_status'] ?? '') !== 'approved') {
        http_response_code(404);
        exit;
    }
}

$abs = member_image_abs_path($profile, $col);
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
