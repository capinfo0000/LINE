<?php

/**
 * 運営コンソール用のヘルパー（会員管理・写真モデレーション・タグ管理・一斉配信・統計）。
 */

declare(strict_types=1);

/* ------------------------- 会員管理 ------------------------- */

/**
 * 会員を検索する（管理用）。キーワードは login_id / email / 表示名 / プロフィール名。
 *
 * @return array<int,array>
 */
function admin_search_members(string $keyword = '', string $status = '', int $limit = 200): array
{
    $where = [];
    $params = [];
    if ($status !== '') {
        $where[] = 'm.status = :status';
        $params[':status'] = $status;
    }
    if (trim($keyword) !== '') {
        $where[] = '(m.login_id LIKE :kw OR m.email LIKE :kw OR m.display_name LIKE :kw OR p.name_text LIKE :kw)';
        $params[':kw'] = '%' . trim($keyword) . '%';
    }
    $sql = 'SELECT m.*, p.name_text, p.photo_status
              FROM members m LEFT JOIN profiles p ON p.member_id = m.id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY m.created_at DESC LIMIT ' . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** 会員の入金履歴を返す。 */
function member_payments(string $memberId): array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE member_id = ? ORDER BY created_at DESC');
    $stmt->execute([$memberId]);
    return $stmt->fetchAll();
}

/** 会員ステータスを変更する（active/suspended/cancelled 等）。 */
function admin_set_member_status(string $memberId, string $status): void
{
    $allowed = ['lead', 'pending_payment', 'active', 'suspended', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        return;
    }
    $stmt = db()->prepare('UPDATE members SET status = ? WHERE id = ?');
    $stmt->execute([$status, $memberId]);
}

/**
 * 会員の資格情報を再発行する（ログインIDは維持、仮パスワードを再生成・初回変更フラグを立てる）。
 * 生成した仮パスワードで配布まで行い、平文は保持しない。
 *
 * @return bool 配布できたら true
 */
function admin_reissue_credentials(string $memberId): bool
{
    $member = find_member_by_id($memberId);
    if ($member === null) {
        return false;
    }
    $temp = generate_temp_password();
    $stmt = db()->prepare('UPDATE members SET password_hash = ?, must_change_pw = 1 WHERE id = ?');
    $stmt->execute([password_hash($temp, PASSWORD_DEFAULT), $memberId]);

    // LINE 再配布のため、配布済みフラグをリセット（本人に紐づく contact があれば）。
    if (!empty($member['line_user_id'])) {
        $r = db()->prepare('UPDATE line_contacts SET credentials_sent = 0 WHERE line_user_id = ?');
        $r->execute([$member['line_user_id']]);
    }
    audit_log('admin.reissue_credentials', ['member' => $memberId]);
    $fresh = find_member_by_id($memberId);
    return deliver_member_credentials($fresh, (string) $fresh['login_id'], $temp);
}

/* ------------------------- 写真モデレーション ------------------------- */

/** 顔写真を承認/却下する。却下時はファイルも削除する。 */
function admin_moderate_photo(string $memberId, string $decision): void
{
    if ($decision === 'approved') {
        $stmt = db()->prepare("UPDATE profiles SET photo_status = 'approved', updated_at = ? WHERE member_id = ?");
        $stmt->execute([time(), $memberId]);
        audit_log('admin.photo_approved', ['member' => $memberId]);
    } elseif ($decision === 'rejected') {
        delete_member_photo($memberId);
        $stmt = db()->prepare("UPDATE profiles SET photo_status = 'rejected', updated_at = ? WHERE member_id = ?");
        $stmt->execute([time(), $memberId]);
        audit_log('admin.photo_rejected', ['member' => $memberId]);
    }
}

/** モデレーション待ち（pending）の写真を持つ会員一覧。 */
function pending_photo_members(): array
{
    return db()->query(
        "SELECT p.member_id, p.photo_path, m.login_id, m.display_name
           FROM profiles p JOIN members m ON m.id = p.member_id
          WHERE p.photo_status = 'pending'
          ORDER BY p.updated_at DESC"
    )->fetchAll();
}

/* ------------------------- タグマスタ管理 ------------------------- */

/** タグを追加する（既存は無視）。 */
function admin_add_tag(string $categoryKey, string $label): void
{
    $categoryKey = trim($categoryKey);
    $label = trim($label);
    if ($categoryKey === '' || $label === '') {
        return;
    }
    $cats = array_column(get_tag_categories(), 'key');
    if (!in_array($categoryKey, $cats, true)) {
        return;
    }
    $stmt = db()->prepare('INSERT OR IGNORE INTO tags (category_key, label, sort) VALUES (?,?,?)');
    $stmt->execute([$categoryKey, $label, 999]);
}

/** タグの有効/無効を切り替える。 */
function admin_set_tag_active(int $tagId, bool $active): void
{
    $stmt = db()->prepare('UPDATE tags SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $tagId]);
}

/* ------------------------- 一斉配信（LINE Push） ------------------------- */

/** 一斉配信の宛先数（active 会員で line_user_id を持つ人数）＝推定課金通数。 */
function broadcast_recipient_count(): int
{
    return (int) db()->query(
        "SELECT COUNT(*) FROM members WHERE status = 'active' AND line_user_id IS NOT NULL AND line_user_id != ''"
    )->fetchColumn();
}

/**
 * active 会員（line_user_id 保有）へ Push 一斉配信する。宛先1件ごとに課金。
 *
 * @return int 送信できた件数
 */
function broadcast_push(string $text): int
{
    $rows = db()->query(
        "SELECT line_user_id FROM members WHERE status = 'active' AND line_user_id IS NOT NULL AND line_user_id != ''"
    )->fetchAll();
    $sent = 0;
    foreach ($rows as $r) {
        if (line_push((string) $r['line_user_id'], [line_text($text)])) {
            $sent++;
        }
    }
    audit_log('admin.broadcast', ['recipients' => count($rows), 'sent' => $sent]);
    return $sent;
}

/* ------------------------- 統計 ------------------------- */

/** ダッシュボード用の集計。 */
function admin_stats(): array
{
    $one = static fn (string $sql) => (int) db()->query($sql)->fetchColumn();
    return [
        'members_total'   => $one('SELECT COUNT(*) FROM members'),
        'members_active'  => $one("SELECT COUNT(*) FROM members WHERE status = 'active'"),
        'payments_paid'   => $one("SELECT COUNT(*) FROM payments WHERE status = 'paid'"),
        'revenue'         => $one("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid'"),
        'pending_photos'  => $one("SELECT COUNT(*) FROM profiles WHERE photo_status = 'pending'"),
        'line_contacts'   => $one('SELECT COUNT(*) FROM line_contacts'),
        'upcoming_bookings' => $one('SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.status = "booked" AND s.start_at > ' . time()),
        'push_this_month' => $one("SELECT COUNT(*) FROM line_messages WHERE billable = 1 AND created_at >= strftime('%s', date('now','start of month'))"),
    ];
}
