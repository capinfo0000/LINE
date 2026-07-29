<?php

/**
 * 会員プロフィール（自由記述・タグ・リンク・顔写真・求める条件・表示制御）のヘルパー。
 */

declare(strict_types=1);

/** 顔写真の保存ディレクトリ（公開領域外）。 */
function uploads_dir(): string
{
    $dir = dirname(current_db_path()) . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/* ------------------------- プロフィール本体 ------------------------- */

/** プロフィールを取得（無ければ空の既定を返す）。 */
function get_profile(string $memberId): array
{
    $stmt = db()->prepare('SELECT * FROM profiles WHERE member_id = ?');
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return [
            'member_id' => $memberId,
            'name_text' => '', 'age_text' => '', 'company_title' => '',
            'headline' => '', 'bio' => '', 'photo_path' => null, 'photo_status' => 'none',
            'visibility_flags' => '{}', 'updated_at' => null,
        ];
    }
    return $row;
}

/** visibility_flags を配列で取得（既定：ディレクトリ掲載ON・LINEリンク表示ON）。 */
function profile_visibility(array $profile): array
{
    $defaults = ['directory' => true, 'line_url' => true];
    $flags = json_decode((string) ($profile['visibility_flags'] ?? '{}'), true);
    if (!is_array($flags)) {
        $flags = [];
    }
    return array_merge($defaults, $flags);
}

/**
 * プロフィール本文を保存（upsert）。
 *
 * @param array{name_text?:string,age_text?:string,company_title?:string,headline?:string,bio?:string,visibility?:array} $d
 */
function save_profile(string $memberId, array $d): void
{
    $existing = get_profile($memberId);
    $vis = $d['visibility'] ?? profile_visibility($existing);
    $visJson = json_encode([
        'directory' => !empty($vis['directory']),
        'line_url'  => !empty($vis['line_url']),
    ], JSON_UNESCAPED_UNICODE);

    $fields = [
        'name_text'     => mb_substr(trim((string) ($d['name_text'] ?? $existing['name_text'])), 0, 100),
        'age_text'      => mb_substr(trim((string) ($d['age_text'] ?? $existing['age_text'])), 0, 40),
        'company_title' => mb_substr(trim((string) ($d['company_title'] ?? $existing['company_title'])), 0, 120),
        'headline'      => mb_substr(trim((string) ($d['headline'] ?? $existing['headline'])), 0, 120),
        'bio'           => mb_substr(trim((string) ($d['bio'] ?? $existing['bio'])), 0, 2000),
    ];

    $stmt = db()->prepare(
        'INSERT INTO profiles (member_id, name_text, age_text, company_title, headline, bio, visibility_flags, updated_at)
         VALUES (:m,:n,:a,:c,:h,:b,:v,:t)
         ON CONFLICT(member_id) DO UPDATE SET
            name_text=:n, age_text=:a, company_title=:c, headline=:h, bio=:b, visibility_flags=:v, updated_at=:t'
    );
    $stmt->execute([
        ':m' => $memberId, ':n' => $fields['name_text'], ':a' => $fields['age_text'],
        ':c' => $fields['company_title'], ':h' => $fields['headline'], ':b' => $fields['bio'],
        ':v' => $visJson, ':t' => time(),
    ]);
}

/* ------------------------- タグ ------------------------- */

/** タグカテゴリ一覧（sort順）。 */
function get_tag_categories(): array
{
    return db()->query('SELECT * FROM tag_categories ORDER BY sort')->fetchAll();
}

/** カテゴリ別の有効タグ [category_key => [ {id,label} ]]。 */
function all_tags_grouped(): array
{
    $rows = db()->query('SELECT id, category_key, label FROM tags WHERE is_active = 1 ORDER BY category_key, sort, label')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['category_key']][] = ['id' => (int) $r['id'], 'label' => $r['label']];
    }
    return $out;
}

/** 指定カテゴリの有効タグID集合。 */
function valid_tag_ids(?string $categoryKey = null): array
{
    if ($categoryKey !== null) {
        $stmt = db()->prepare('SELECT id FROM tags WHERE is_active = 1 AND category_key = ?');
        $stmt->execute([$categoryKey]);
    } else {
        $stmt = db()->query('SELECT id FROM tags WHERE is_active = 1');
    }
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

/** 会員のタグID一覧。 */
function get_member_tag_ids(string $memberId): array
{
    $stmt = db()->prepare('SELECT tag_id FROM member_tags WHERE member_id = ?');
    $stmt->execute([$memberId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'tag_id'));
}

/**
 * 会員のタグを保存（有効タグIDのみ受理し、全置換）。
 *
 * @param int[] $tagIds
 */
function set_member_tags(string $memberId, array $tagIds): void
{
    $valid = array_flip(valid_tag_ids());
    $clean = [];
    foreach ($tagIds as $id) {
        $id = (int) $id;
        if (isset($valid[$id])) {
            $clean[$id] = true;
        }
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM member_tags WHERE member_id = ?');
        $del->execute([$memberId]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO member_tags (member_id, tag_id) VALUES (?, ?)');
        foreach (array_keys($clean) as $id) {
            $ins->execute([$memberId, $id]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ------------------------- リンク ------------------------- */

/** 会員のリンク一覧（sort順）。 */
function get_member_links(string $memberId): array
{
    $stmt = db()->prepare('SELECT * FROM member_links WHERE member_id = ? ORDER BY sort_order, id');
    $stmt->execute([$memberId]);
    return $stmt->fetchAll();
}

/** URL が http/https の妥当な形式か。 */
function is_valid_link_url(string $url): bool
{
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * 会員のリンクを全置換（最大10件、妥当なURLのみ）。
 *
 * @param array<int,array{kind?:string,label?:string,url:string}> $links
 */
function set_member_links(string $memberId, array $links): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM member_links WHERE member_id = ?');
        $del->execute([$memberId]);
        $ins = $pdo->prepare('INSERT INTO member_links (member_id, kind, label, url, sort_order) VALUES (?,?,?,?,?)');
        $order = 0;
        foreach ($links as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '' || !is_valid_link_url($url)) {
                continue;
            }
            $kind = ($link['kind'] ?? '') === 'line_add' ? 'line_add' : 'other';
            $label = mb_substr(trim((string) ($link['label'] ?? '')), 0, 60);
            $ins->execute([$memberId, $kind, $label, mb_substr($url, 0, 500), $order++]);
            if ($order >= 10) {
                break;
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ------------------------- 求める条件 ------------------------- */

/** 求める条件を取得（各軸 tag_id 配列）。 */
function get_preferences(string $memberId): array
{
    $stmt = db()->prepare('SELECT * FROM match_preferences WHERE member_id = ?');
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    $decode = static function ($j): array {
        $a = json_decode((string) $j, true);
        return is_array($a) ? array_map('intval', $a) : [];
    };
    return [
        'seek_area'    => $decode($row['seek_area'] ?? '[]'),
        'seek_job'     => $decode($row['seek_job'] ?? '[]'),
        'seek_purpose' => $decode($row['seek_purpose'] ?? '[]'),
    ];
}

/** 求める条件を保存（各軸で有効タグIDのみ受理）。 */
function save_preferences(string $memberId, array $seekArea, array $seekJob, array $seekPurpose): void
{
    $filter = static function (array $ids, string $cat): array {
        $valid = array_flip(valid_tag_ids($cat));
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($valid[$id])) {
                $out[$id] = true;
            }
        }
        return array_keys($out);
    };
    $a = json_encode($filter($seekArea, 'area'));
    $j = json_encode($filter($seekJob, 'job'));
    $p = json_encode($filter($seekPurpose, 'purpose'));
    $stmt = db()->prepare(
        'INSERT INTO match_preferences (member_id, seek_area, seek_job, seek_purpose, updated_at)
         VALUES (:m,:a,:j,:p,:t)
         ON CONFLICT(member_id) DO UPDATE SET seek_area=:a, seek_job=:j, seek_purpose=:p, updated_at=:t'
    );
    $stmt->execute([':m' => $memberId, ':a' => $a, ':j' => $j, ':p' => $p, ':t' => time()]);
}

/* ------------------------- 顔写真 ------------------------- */

/**
 * JPEG の EXIF Orientation に従って GD 画像を正しい向きに補正して返す。
 * スマホ写真は撮影向きが EXIF にだけ入っていることが多く、補正しないと横倒しで保存される。
 *
 * @param \GdImage|resource $img
 * @return \GdImage|resource 補正後の画像（回転で新インスタンスになる場合あり）
 */
function gd_apply_exif_orientation($img, string $tmp, string $mime)
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($tmp);
    $orientation = (int) ($exif['Orientation'] ?? 0);
    if ($orientation <= 1) {
        return $img; // 補正不要
    }
    // imagerotate は反時計回り。向き 6/8 のスマホ縦写真を想定して補正する。
    switch ($orientation) {
        case 2:
            imageflip($img, IMG_FLIP_HORIZONTAL);
            break;
        case 3:
            $img = imagerotate($img, 180, 0);
            break;
        case 4:
            imageflip($img, IMG_FLIP_VERTICAL);
            break;
        case 5:
            $img = imagerotate($img, -90, 0);
            imageflip($img, IMG_FLIP_HORIZONTAL);
            break;
        case 6:
            $img = imagerotate($img, -90, 0);
            break;
        case 7:
            $img = imagerotate($img, 90, 0);
            imageflip($img, IMG_FLIP_HORIZONTAL);
            break;
        case 8:
            $img = imagerotate($img, 90, 0);
            break;
    }
    return $img;
}

/**
 * アップロードされた顔写真を検証して保存する。成功で true。
 * 巨大な原本を受け取ってよい（サーバの upload_max_filesize は .user.ini で引き上げ）。
 * その上でサーバ側が以下の処理を行い、できるだけ小さくした1ファイルだけを残す：
 * - 画像形式（jpeg/png/webp）と極端なサイズ上限（<= 20MB）を検証。
 * - GD がある場合：EXIF の向きを補正 → 中央を正方形にクロップ → 512px に縮小 →
 *   WebP に統一し、目標サイズ以下になるよう品質を段階的に下げて圧縮（imagewebp が
 *   無ければ JPEG）。再エンコードで埋め込みメタデータ/ペイロードも除去。
 *   GD が無い場合のみ、検証済みの元ファイルをそのまま保存（変換不可のフォールバック）。
 * - 保存は公開領域外（data/uploads/）。会員あたり常に1ファイルのみで、原本は保存しない
 *   （旧拡張子ファイルも削除）。photo_status は 'approved'（承認フローは廃止・即公開）。
 *
 * @param array{tmp_name?:string,size?:int,error?:int} $file $_FILES のエントリ
 */
function save_member_photo(string $memberId, array $file, string &$error = ''): bool
{
    $errCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($errCode !== UPLOAD_ERR_OK) {
        // サーバの upload_max_filesize / post_max_size を超えて弾かれた場合の案内。
        // 上限は public/.user.ini で引き上げ済み。反映されていない可能性も添える。
        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            $error = '画像の容量が大きすぎてアップロードできませんでした（サーバ上限未反映の可能性）。時間をおくか、少し小さい画像でお試しください。';
        } else {
            $error = '画像のアップロードに失敗しました。';
        }
        return false;
    }
    if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
        $error = '画像サイズが大きすぎます（20MBまで）。';
        return false;
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_readable($tmp)) {
        $error = '画像を読み取れませんでした。';
        return false;
    }
    $info = @getimagesize($tmp);
    if ($info === false) {
        $error = '画像ファイルとして認識できませんでした。';
        return false;
    }
    $mime = $info['mime'] ?? '';
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extMap[$mime])) {
        $error = '対応していない画像形式です（JPEG/PNG/WebP のみ）。';
        return false;
    }
    $fallbackExt = $extMap[$mime]; // GD 不可時に元形式で保存するための拡張子
    $dir = uploads_dir();
    $targetPx = 512; // 出力の一辺（正方形）

    $processed = false;
    $outExt = null;
    $dest = null;

    // GD があれば：EXIF回転補正 → 中央正方形クロップ → 512px → WebP（無ければ JPEG）で保存。
    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($src !== false) {
            $src = gd_apply_exif_orientation($src, $tmp, $mime); // スマホ写真の横倒しを補正
            $w = imagesx($src);
            $h = imagesy($src);
            $side = max(1, min($w, $h));            // 短辺に合わせて正方形に
            $sx = (int) (($w - $side) / 2);         // 中央基準の切り出し原点
            $sy = (int) (($h - $side) / 2);

            $dst = imagecreatetruecolor($targetPx, $targetPx);
            $useWebp = function_exists('imagewebp');
            if ($useWebp) {
                // WebP は透過を保持できるので、背景を透明で初期化。
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                $bg = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            } else {
                // JPEG は透過不可 → 白背景で平坦化。
                $bg = imagecolorallocate($dst, 255, 255, 255);
            }
            imagefilledrectangle($dst, 0, 0, $targetPx, $targetPx, $bg);
            imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $targetPx, $targetPx, $side, $side);

            if (!$useWebp) {
                imageinterlace($dst, true); // JPEG フォールバックはプログレッシブで軽量＆表示良化
            }
            $outExt = $useWebp ? 'webp' : 'jpg';
            $dest = $dir . '/' . $memberId . '.' . $outExt;
            // 「できるだけ小さく」：目標(既定80KB)以下になるまで品質を段階的に下げて再エンコード。
            // 512px の顔写真なら通常は最初の品質で目標を満たす。品質は下限で打ち止め（画質保護）。
            $targetBytes = 80 * 1024;
            $qualities = $useWebp ? [72, 62, 52, 42] : [78, 68, 58, 48];
            foreach ($qualities as $q) {
                $processed = $useWebp ? imagewebp($dst, $dest, $q) : imagejpeg($dst, $dest, $q);
                if (!$processed) {
                    break;
                }
                clearstatcache(true, $dest);
                if (filesize($dest) <= $targetBytes) {
                    break; // 目標達成
                }
            }
            imagedestroy($dst);
            imagedestroy($src);
        }
    }

    if (!$processed) {
        // GD 不可：検証済みの元ファイルをそのまま保存（トリミング/変換なし）。
        $outExt = $fallbackExt;
        $dest = $dir . '/' . $memberId . '.' . $outExt;
        if (!@copy($tmp, $dest)) {
            $error = '画像の保存に失敗しました。';
            return false;
        }
    }
    @chmod($dest, 0600);

    // 既存の別拡張子ファイルを掃除。
    foreach (['jpg', 'png', 'webp'] as $e2) {
        if ($e2 !== $outExt) {
            @unlink($dir . '/' . $memberId . '.' . $e2);
        }
    }

    $rel = 'uploads/' . $memberId . '.' . $outExt;
    $stmt = db()->prepare(
        "INSERT INTO profiles (member_id, photo_path, photo_status, updated_at)
         VALUES (:m,:p,'approved',:t)
         ON CONFLICT(member_id) DO UPDATE SET photo_path=:p, photo_status='approved', updated_at=:t"
    );
    $stmt->execute([':m' => $memberId, ':p' => $rel, ':t' => time()]);
    return true;
}

/** 顔写真の絶対パス（無ければ null）。 */
function member_photo_abs_path(array $profile): ?string
{
    $rel = (string) ($profile['photo_path'] ?? '');
    if ($rel === '') {
        return null;
    }
    $abs = dirname(current_db_path()) . '/' . $rel;
    return is_file($abs) ? $abs : null;
}

/** 顔写真を削除する。 */
function delete_member_photo(string $memberId): void
{
    $profile = get_profile($memberId);
    $abs = member_photo_abs_path($profile);
    if ($abs !== null) {
        @unlink($abs);
    }
    $stmt = db()->prepare("UPDATE profiles SET photo_path = NULL, photo_status = 'none', updated_at = ? WHERE member_id = ?");
    $stmt->execute([time(), $memberId]);
}
