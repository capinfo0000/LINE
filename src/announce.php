<?php

/**
 * 「さがす」上部のお知らせ（スライド）を管理画面から出し入れするための仕組み。
 *
 * 以前は directory.php に3枚を直書きしていたため、文面を変えるだけでも
 * ファイルを差し替える必要があった。ここでDBに持たせて、追加・編集・削除・
 * 並べ替え・公開/非公開を運営側で行えるようにする。
 */

declare(strict_types=1);

/**
 * スライドの配色。キーはCSSのクラス名（.tp-pslide--◯◯）に対応する。
 *
 * @return array<string,string>
 */
function announcement_themes(): array
{
    return [
        'brand' => 'ダークネイビー（ブランド）',
        'ref'   => 'オレンジ（キャンペーン）',
        'rank'  => 'ゴールド（ランキング）',
    ];
}

/** 初期状態として入れておくお知らせ（テーブルが空のときだけ投入する）。 */
function announcement_seed_rows(): array
{
    return [
        ['ENLINK', 'ビジネスの縁が、ここから。', '相性で出会う、次のパートナー', '/member/recommend', 'brand'],
        ['紹介キャンペーン', '5人ご紹介で、翌月無料。', '紹介した仲間が続くほどおトクに', '/member/points', 'ref'],
        ['RANKING', '実績ポイント・ランキング', '活躍している会員をチェック', '/member/directory?tab=ranking', 'rank'],
    ];
}

/** 会員に表示するお知らせ（公開中のみ、並び順）。 */
function active_announcements(): array
{
    $stmt = db()->query(
        'SELECT * FROM announcements WHERE is_active = 1 ORDER BY sort_order, id LIMIT 10'
    );
    return $stmt->fetchAll() ?: [];
}

/** 管理画面用（非公開も含めた全件）。 */
function all_announcements(): array
{
    return db()->query('SELECT * FROM announcements ORDER BY sort_order, id')->fetchAll() ?: [];
}

/** 1件取得。無ければ null。 */
function find_announcement(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM announcements WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * お知らせのリンク先として許可するURLか。
 * 会員サイト内の相対パスと https:// のみ。「//」で始まるものは外部サイトへ飛ぶため不可。
 */
function is_valid_announcement_url(string $url): bool
{
    if ($url === '') {
        return true; // 未設定＝リンクなしのカードとして扱う
    }
    if (strncmp($url, '//', 2) === 0) {
        return false;
    }
    if (strncmp($url, '/', 1) === 0) {
        return (bool) preg_match('#^/[A-Za-z0-9._/?=&\#%+-]*$#', $url);
    }
    return (bool) preg_match('#^https://[^\s"\'<>]+$#', $url);
}

/**
 * 追加・更新。$id が 0 なら新規。
 * 何が足りないのかを呼び出し側に返して、画面で理由を出せるようにする。
 *
 * @return array{ok:bool, message:string}
 */
function announcement_save(int $id, array $in): array
{
    $label = clean_line_text((string) ($in['label'] ?? ''));
    $title = clean_line_text((string) ($in['title'] ?? ''));
    $sub   = clean_line_text((string) ($in['subtitle'] ?? ''));
    $url   = trim((string) ($in['url'] ?? ''));
    $theme = (string) ($in['theme'] ?? 'brand');
    $sort  = (int) ($in['sort_order'] ?? 0);
    $active = !empty($in['is_active']) ? 1 : 0;

    if ($title === '') {
        return ['ok' => false, 'message' => '見出しが未入力です。スライドに大きく出る文言を入力してください。'];
    }
    if (mb_strlen($title) > 40) {
        return ['ok' => false, 'message' => '見出しが長すぎます。40文字以内にしてください（スライドからはみ出します）。'];
    }
    if (mb_strlen($label) > 20) {
        return ['ok' => false, 'message' => '小見出しが長すぎます。20文字以内にしてください。'];
    }
    if (mb_strlen($sub) > 60) {
        return ['ok' => false, 'message' => '説明が長すぎます。60文字以内にしてください。'];
    }
    if (!isset(announcement_themes()[$theme])) {
        return ['ok' => false, 'message' => '配色が正しくありません。一覧から選び直してください。'];
    }
    if (!is_valid_announcement_url($url)) {
        return ['ok' => false, 'message' => 'リンク先が正しくありません。「/member/points」のようなサイト内のパスか、「https://」から始まるURLを入力してください。'];
    }

    $now = time();
    if ($id > 0) {
        if (find_announcement($id) === null) {
            return ['ok' => false, 'message' => '対象のお知らせが見つかりませんでした。'];
        }
        $stmt = db()->prepare(
            'UPDATE announcements SET label = ?, title = ?, subtitle = ?, url = ?, theme = ?,
                    sort_order = ?, is_active = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$label, $title, $sub, $url, $theme, $sort, $active, $now, $id]);
        audit_log('admin.announcement_saved', ['id' => $id]);
        return ['ok' => true, 'message' => 'お知らせ「' . $title . '」を更新しました。'];
    }

    if ((int) db()->query('SELECT COUNT(*) FROM announcements')->fetchColumn() >= 10) {
        return ['ok' => false, 'message' => 'お知らせは10件までです。不要なものを削除してから追加してください。'];
    }
    $stmt = db()->prepare(
        'INSERT INTO announcements (label, title, subtitle, url, theme, sort_order, is_active, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([$label, $title, $sub, $url, $theme, $sort, $active, $now, $now]);
    audit_log('admin.announcement_added', ['title' => $title]);
    return ['ok' => true, 'message' => 'お知らせ「' . $title . '」を追加しました。'];
}

/** 削除。存在しなければ false。 */
function announcement_delete(int $id): bool
{
    $stmt = db()->prepare('DELETE FROM announcements WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() > 0) {
        audit_log('admin.announcement_deleted', ['id' => $id]);
        return true;
    }
    return false;
}
