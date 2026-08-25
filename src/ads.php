<?php

/**
 * 広告枠。運営が画像とリンク先を登録して、会員画面に出す。
 *
 * 外部の広告配信（AdSense 等）は使わない。外部のスクリプトと画像を許可するには
 * CSP を緩める必要があり、これまでの対策を弱めることになるため。
 * ここで扱うのは「運営が自分で用意した画像＋リンク」だけなので、CSP は現状のまま。
 *
 * 枠は2つ。
 *   side … PC の左右の空きに置く縦型（160×600 想定）。画面が広いときだけ出る
 *   feed … スマホでも見える位置。「さがす」の会員カードの間に1枠だけ挟む
 *
 * リンク先の検証は、お知らせと同じ考え方（is_valid_announcement_url）を使う。
 * javascript: などを弾き、外部サイトへは https のみ許す。
 */

declare(strict_types=1);

/**
 * 広告の表示そのものを、まとめて止められるようにする。
 *
 * 個別の広告にも「掲載する/止める」があるが、それとは別に全体のスイッチを持つ。
 * 提携先とのトラブル、掲載内容の差し替え待ち、キャンペーンの終了など
 * 「とりあえず全部止めたい」場面で、広告を1件ずつ触らずに済ませるため。
 * 個別の設定は残るので、ONに戻せば元の状態に戻る。
 *
 * 既定はON。OFFが既定だと、広告を登録したのに出ない理由が分からなくなる。
 */
function ads_enabled(): bool
{
    return app_setting_get('ads_enabled', '1') !== '0';
}

/** 全体のスイッチを切り替える。 */
function ads_set_enabled(bool $on): void
{
    app_setting_set('ads_enabled', $on ? '1' : '0');
    audit_log('admin.ads_enabled', ['on' => $on ? 1 : 0]);
}

/** 枠の種類と、運営画面に出す説明。 */
function ad_slots(): array
{
    return [
        'side' => ['label' => 'PCの左右（縦型）', 'hint' => '推奨 160×600。画面幅が1520px以上のときだけ出ます', 'w' => 160, 'h' => 600],
        'feed' => ['label' => 'さがすの一覧の中', 'hint' => '推奨 1200×400（横3：縦1）。スマホでもPCでも出ます', 'w' => 1200, 'h' => 400],
        'bar'  => ['label' => '画面の一番下（タブバーの上）', 'hint' => '推奨 1200×170（横7：縦1の細長い帯）。2件以上入れると自動で流れます', 'w' => 1200, 'h' => 170],
    ];
}

/** 画像1枚の上限。 */
const AD_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

/** 広告画像の置き場所（公開領域の外）。 */
function ads_dir(): string
{
    $dir = dirname(current_db_path()) . '/ads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * いま出せる広告を、枠ごとに返す。
 *
 * 掲載期間と有効フラグで絞る。複数あるときは weight の大きいものが出やすいように
 * 重み付きで1つ選ぶ（毎回同じものだけが出ないようにする）。
 *
 * @return array<int, array> 表示する広告（最大 $limit 件）
 */
function ads_for_slot(string $slot, int $limit = 1): array
{
    // 全体のスイッチがOFFなら、ここで打ち切る。表示する側の分岐を増やさずに
    // 1箇所で効かせるため、抽出の入口に置いている。
    if (!ads_enabled() || !isset(ad_slots()[$slot])) {
        return [];
    }
    $now = time();
    $stmt = db()->prepare(
        'SELECT * FROM ads
          WHERE slot = ? AND is_active = 1 AND image_path IS NOT NULL
            AND (starts_at IS NULL OR starts_at <= ?)
            AND (ends_at   IS NULL OR ends_at   >= ?)
          ORDER BY id'
    );
    $stmt->execute([$slot, $now, $now]);
    $rows = $stmt->fetchAll() ?: [];

    // 画像の実ファイルが無いものは外す。DBにパスが残っていてもファイルが消えている
    // ことはある（手で消した、保存が途中で失敗した等）。そのまま出すと
    // 画像が表示されない空の枠になるので、最初から候補に入れない。
    $rows = array_values(array_filter($rows, static fn (array $r): bool => ad_image_abs_path($r) !== null));

    if ($rows === [] || count($rows) <= $limit) {
        return $rows;
    }

    // 重み付きで選ぶ。weight を「くじの枚数」と見て、そこから引く。
    $picked = [];
    for ($n = 0; $n < $limit && $rows !== []; $n++) {
        $total = 0;
        foreach ($rows as $r) {
            $total += max(1, (int) $r['weight']);
        }
        $hit = random_int(1, $total);
        $acc = 0;
        foreach ($rows as $k => $r) {
            $acc += max(1, (int) $r['weight']);
            if ($acc >= $hit) {
                $picked[] = $r;
                unset($rows[$k]);
                $rows = array_values($rows);
                break;
            }
        }
    }
    return $picked;
}

/** 表示回数を数える（表示した広告のIDをまとめて渡す）。 */
function ads_count_impressions(array $ids): void
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE ads SET impressions = impressions + 1 WHERE id IN ({$in})")->execute($ids);
}

/** クリックを数える。存在しなければ false。 */
function ad_count_click(int $id): bool
{
    $stmt = db()->prepare('UPDATE ads SET clicks = clicks + 1 WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function find_ad(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM ads WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** 運営画面の一覧（期限切れも含めて全部）。 */
function all_ads(): array
{
    return db()->query('SELECT * FROM ads ORDER BY slot, id DESC')->fetchAll() ?: [];
}

/** 画像の絶対パス（無ければ null）。 */
function ad_image_abs_path(array $ad): ?string
{
    $rel = (string) ($ad['image_path'] ?? '');
    if ($rel === '') {
        return null;
    }
    $abs = dirname(current_db_path()) . '/' . $rel;
    return is_file($abs) ? $abs : null;
}

/** 画像を配る URL。中身が変わったら version が変わるので、古い画像が残らない。 */
function ad_image_url(array $ad): string
{
    return '/member/ad_image?id=' . (int) $ad['id'] . '&v=' . (int) ($ad['updated_at'] ?? 0);
}

/** クリックを数えて遷移する URL。 */
function ad_click_url(array $ad): string
{
    return '/member/ad_click?id=' . (int) $ad['id'];
}

/**
 * 広告画像を保存する。
 *
 * 会員写真と同じ考え方で、受け取った画像を GD で作り直してから保存する。
 * 元ファイルに埋め込まれたメタデータや余計なものを落とすため。
 * 透過を保つため WebP を優先し、使えないときは PNG にする（広告はロゴを含むことが
 * 多く、JPEG にすると文字がにじむ）。
 */
function ad_save_image(int $adId, array $file, string &$error = ''): ?string
{
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code !== UPLOAD_ERR_OK) {
        $error = upload_error_message($code);
        return null;
    }
    if (($file['size'] ?? 0) > AD_IMAGE_MAX_BYTES) {
        $error = '画像が大きすぎます（5MBまで）。';
        return null;
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp) || !is_readable($tmp)) {
        $error = '画像を読み取れませんでした。';
        return null;
    }
    $info = @getimagesize($tmp);
    if ($info === false) {
        $error = '画像ファイルとして認識できませんでした。';
        return null;
    }
    if (!isset(['image/jpeg' => 1, 'image/png' => 1, 'image/webp' => 1][$info['mime'] ?? ''])) {
        $error = '対応していない画像形式です（JPEG/PNG/WebP のみ）。';
        return null;
    }

    $dir = ads_dir();
    $ext = 'png';
    $dest = null;
    $done = false;

    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);
            // 縦型は幅320、横型は幅1200を上限にする（表示は等倍か縮小のみ）。
            $maxW = $w < $h ? 320 : 1200;
            $scale = $w > $maxW ? $maxW / $w : 1.0;
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $useWebp = function_exists('imagewebp');
            $ext = $useWebp ? 'webp' : 'png';
            $dest = $dir . '/' . $adId . '.' . $ext;
            $done = $useWebp ? imagewebp($dst, $dest, 88) : imagepng($dst, $dest, 9);
            imagedestroy($dst);
            imagedestroy($src);
        }
    }
    if (!$done) {
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']];
        $dest = $dir . '/' . $adId . '.' . $ext;
        if (!@copy($tmp, $dest)) {
            $error = '画像の保存に失敗しました。';
            return null;
        }
    }
    @chmod($dest, 0600);
    // 古い拡張子のファイルを掃除する（1広告につき常に1ファイル）。
    foreach (['jpg', 'png', 'webp'] as $e2) {
        if ($e2 !== $ext) {
            @unlink($dir . '/' . $adId . '.' . $e2);
        }
    }
    return 'ads/' . $adId . '.' . $ext;
}

/** 画像ファイルを消す。 */
function ad_delete_image(int $adId): void
{
    foreach (['jpg', 'png', 'webp'] as $e) {
        @unlink(ads_dir() . '/' . $adId . '.' . $e);
    }
}

/**
 * 保存（新規・更新の両方）。
 *
 * @return array{ok:bool, message:string, id:int}
 */
function ad_save(int $id, array $in, array $files): array
{
    $slot = (string) ($in['slot'] ?? '');
    if (!isset(ad_slots()[$slot])) {
        return ['ok' => false, 'message' => '掲載場所を選んでください。', 'id' => $id];
    }
    $title = mb_substr(clean_line_text((string) ($in['title'] ?? '')), 0, 100);
    if ($title === '') {
        return ['ok' => false, 'message' => '管理用の名前を入れてください（会員には表示されません）。', 'id' => $id];
    }
    $alt = mb_substr(clean_line_text((string) ($in['alt'] ?? '')), 0, 120);
    $url = trim((string) ($in['url'] ?? ''));
    if ($url !== '' && !is_valid_announcement_url($url)) {
        return ['ok' => false, 'message' => 'リンク先が正しくありません（https:// で始まるURL、または / で始まるサイト内のパス）。', 'id' => $id];
    }
    $weight = max(1, min(100, (int) ($in['weight'] ?? 1)));
    $toTs = static function (string $v): ?int {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $t = strtotime($v);
        return $t === false ? null : $t;
    };
    $starts = $toTs((string) ($in['starts_at'] ?? ''));
    $ends = $toTs((string) ($in['ends_at'] ?? ''));
    if ($starts !== null && $ends !== null && $ends < $starts) {
        return ['ok' => false, 'message' => '掲載の終了日が開始日より前になっています。', 'id' => $id];
    }
    $active = !empty($in['is_active']) ? 1 : 0;
    $now = time();

    if ($id > 0) {
        if (find_ad($id) === null) {
            return ['ok' => false, 'message' => '対象の広告が見つかりませんでした。', 'id' => 0];
        }
        db()->prepare(
            'UPDATE ads SET title=?, slot=?, url=?, alt=?, weight=?, starts_at=?, ends_at=?, is_active=?, updated_at=? WHERE id=?'
        )->execute([$title, $slot, $url, $alt, $weight, $starts, $ends, $active, $now, $id]);
    } else {
        db()->prepare(
            'INSERT INTO ads (title, slot, url, alt, weight, starts_at, ends_at, is_active, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$title, $slot, $url, $alt, $weight, $starts, $ends, $active, $now, $now]);
        $id = (int) db()->lastInsertId();
    }

    // 画像は行ができたあとに保存する（ファイル名にIDを使うため）。
    $msg = $id > 0 ? '保存しました。' : '';
    $f = $files['image'] ?? null;
    if (is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $err = '';
        $rel = ad_save_image($id, $f, $err);
        if ($rel === null) {
            $msg .= '（画像は保存できませんでした：' . $err . '）';
        } else {
            db()->prepare('UPDATE ads SET image_path = ?, updated_at = ? WHERE id = ?')->execute([$rel, time(), $id]);
            $msg .= '（画像を差し替えました）';
        }
    }
    audit_log('admin.ad_save', ['id' => $id, 'slot' => $slot, 'active' => $active]);
    return ['ok' => true, 'message' => $msg, 'id' => $id];
}

/** 削除（画像ファイルも消す）。 */
function ad_delete(int $id): bool
{
    if (find_ad($id) === null) {
        return false;
    }
    ad_delete_image($id);
    db()->prepare('DELETE FROM ads WHERE id = ?')->execute([$id]);
    audit_log('admin.ad_delete', ['id' => $id]);
    return true;
}

/**
 * 広告1枚のHTMLを組み立てる。
 *
 * ・リンクがあれば <a>、無ければ <div>。押せないものをリンクに見せない。
 * ・外部サイトへは target="_blank" と rel="noopener nofollow sponsored"。
 *   nofollow と sponsored は「これは広告」と検索側に伝えるための決まりで、
 *   付けないと自サイトの評価にも影響しうる。
 * ・右上に「広告」と出す。会員のコンテンツと見分けられるようにするため
 *   （景表法・ステルスマーケティングの規制対策でもある）。
 */
function ad_html(array $ad, string $slot): string
{
    $url = (string) $ad['url'];
    $isExternal = $url !== '' && strncmp($url, 'http', 4) === 0;
    $img = '<img src="' . e(ad_image_url($ad)) . '" alt="' . e((string) $ad['alt']) . '" loading="lazy" decoding="async">';
    $badge = '<span class="ad__tag">広告</span>';
    $cls = 'ad ad--' . $slot;

    if ($url === '') {
        return '<div class="' . $cls . '">' . $img . $badge . '</div>';
    }
    return '<a class="' . $cls . '" href="' . e(ad_click_url($ad)) . '"'
        . ($isExternal ? ' target="_blank" rel="noopener nofollow sponsored"' : ' rel="nofollow sponsored"')
        . '>' . $img . $badge . '</a>';
}

/**
 * 枠に出す広告を1枚ずつHTMLにして返し、表示回数を数える。
 * 左右のレールのように「1枚ずつ別の場所へ置きたい」場合があるので、
 * 連結した文字列ではなく配列で返す。
 *
 * @return array<int,string> 出すものが無ければ空配列
 */
function ads_render_each(string $slot, int $limit = 1): array
{
    $ads = ads_for_slot($slot, $limit);
    if ($ads === []) {
        return [];
    }
    $out = [];
    $ids = [];
    foreach ($ads as $ad) {
        $out[] = ad_html($ad, $slot);
        $ids[] = (int) $ad['id'];
    }
    ads_count_impressions($ids);
    return $out;
}

/** 1枠ぶんをそのまま埋め込みたいとき用（連結して返す）。 */
function ads_render(string $slot, int $limit = 1): string
{
    return implode('', ads_render_each($slot, $limit));
}
