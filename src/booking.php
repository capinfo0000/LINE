<?php

/**
 * 予約モジュール（説明会 / 個別面談）。
 *
 * - slots: 予約枠（capacity・開催時刻・Zoom）。admin/CLI で作成。
 * - bookings: 予約。空き枠を原子的に確保して作成する。
 * - Zoom 会議は枠単位で遅延生成（初回予約時）。作成失敗時は枠だけ確定＋手動URL案内にフォールバック。
 */

declare(strict_types=1);

function generate_slot_id(): string
{
    return 'slot_' . bin2hex(random_bytes(5));
}

function generate_booking_id(): string
{
    return 'bk_' . bin2hex(random_bytes(6));
}

/** 予約枠を作成する。 */
function create_slot(string $kind, int $startAt, int $capacity = 1): string
{
    $id = generate_slot_id();
    $stmt = db()->prepare(
        'INSERT INTO slots (id, kind, start_at, capacity, booked_count, is_open, created_at) VALUES (?,?,?,?,0,1,?)'
    );
    $stmt->execute([$id, $kind, $startAt, max(1, $capacity), time()]);
    return $id;
}

function find_slot(string $slotId): ?array
{
    $stmt = db()->prepare('SELECT * FROM slots WHERE id = ?');
    $stmt->execute([$slotId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * 予約可能な枠（未来・空きあり・オープン）を新しい順で返す。
 *
 * @return array<int,array>
 */
function open_slots(string $kind, int $limit = 10): array
{
    $stmt = db()->prepare(
        'SELECT * FROM slots
          WHERE kind = ? AND is_open = 1 AND booked_count < capacity AND start_at > ?
          ORDER BY start_at ASC LIMIT ?'
    );
    $stmt->execute([$kind, time(), $limit]);
    return $stmt->fetchAll();
}

/**
 * 枠を原子的に確保して予約を作成する。満席・無効なら null。
 * Zoom は枠単位で遅延生成（失敗時は枠だけ確定・zoom_url は null）。
 *
 * @return array{booking_id:string, zoom_url:?string}|null
 */
function book_slot(string $slotId, string $kind, ?string $lineUserId, ?string $memberId): ?array
{
    // 原子的な空き確保（capacity 超過・クローズを排除）。
    $claim = db()->prepare(
        'UPDATE slots SET booked_count = booked_count + 1
          WHERE id = ? AND kind = ? AND is_open = 1 AND booked_count < capacity AND start_at > ?'
    );
    $claim->execute([$slotId, $kind, time()]);
    if ($claim->rowCount() === 0) {
        return null; // 満席・無効・過去
    }

    $slot = find_slot($slotId);

    // Zoom 会議を枠単位で遅延生成（未生成かつ設定済みのとき）。失敗はフォールバック。
    $zoomUrl = $slot['zoom_url'] ?? null;
    if (($zoomUrl === null || $zoomUrl === '') && zoom_enabled()) {
        $duration = $kind === 'seminar' ? 40 : 30;
        $topic = $kind === 'seminar' ? 'Enlink 説明会' : 'Enlink 個別面談';
        $meeting = zoom_create_meeting($topic, (int) $slot['start_at'], $duration);
        if ($meeting !== null) {
            $zoomUrl = $meeting['join_url'];
            $u = db()->prepare('UPDATE slots SET zoom_meeting_id = ?, zoom_url = ? WHERE id = ?');
            $u->execute([$meeting['id'], $zoomUrl, $slotId]);
        }
    }

    $bookingId = generate_booking_id();
    $ins = db()->prepare(
        'INSERT INTO bookings (id, kind, line_user_id, member_id, slot_id, status, zoom_url, created_at)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $ins->execute([$bookingId, $kind, $lineUserId, $memberId, $slotId, 'booked', $zoomUrl, time()]);

    return ['booking_id' => $bookingId, 'zoom_url' => $zoomUrl];
}

/** 予約のステータスを更新する。 */
function set_booking_status(string $bookingId, string $status): void
{
    $stmt = db()->prepare('UPDATE bookings SET status = ? WHERE id = ?');
    $stmt->execute([$status, $bookingId]);
}

/**
 * リマインド対象の予約（開始が now〜now+window、未リマインド、booked）を返す。
 *
 * @return array<int,array>
 */
function bookings_needing_reminder(int $windowSec = 3600): array
{
    $stmt = db()->prepare(
        'SELECT b.*, s.start_at AS slot_start
           FROM bookings b JOIN slots s ON s.id = b.slot_id
          WHERE b.status = ? AND b.remind_sent = 0
            AND s.start_at > ? AND s.start_at <= ?'
    );
    $stmt->execute(['booked', time(), time() + $windowSec]);
    return $stmt->fetchAll();
}

/** リマインド送信済みマーク（冪等）。既送信なら false。 */
function claim_reminder(string $bookingId): bool
{
    $stmt = db()->prepare('UPDATE bookings SET remind_sent = 1 WHERE id = ? AND remind_sent = 0');
    $stmt->execute([$bookingId]);
    return $stmt->rowCount() > 0;
}

/* ------------------------- 交流グループ（オープンチャット） ------------------------- */

/** 有効なオープンチャットの招待URL（運営が登録した最新1件）。無ければ null。 */
function active_openchat_url(): ?string
{
    $stmt = db()->query("SELECT invite_url FROM groups WHERE kind = 'openchat' AND is_active = 1 AND invite_url IS NOT NULL ORDER BY created_at DESC LIMIT 1");
    $url = $stmt->fetchColumn();
    return $url !== false && $url !== null && $url !== '' ? (string) $url : null;
}
