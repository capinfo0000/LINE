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

/**
 * 予約枠を作成する。Zoom 設定済みなら「枠作成の時点で」会議を発行して枠に保存する
 * （予約者はブッキング時に即その URL を受け取れる＝手渡し）。未設定・失敗時は枠だけ作成し、
 * book_slot 側でのフォールバック（予約時に再試行）に委ねる。
 */
function create_slot(string $kind, int $startAt, int $capacity = 1): string
{
    $id = generate_slot_id();
    $stmt = db()->prepare(
        'INSERT INTO slots (id, kind, start_at, capacity, booked_count, is_open, created_at) VALUES (?,?,?,?,0,1,?)'
    );
    $stmt->execute([$id, $kind, $startAt, max(1, $capacity), time()]);

    // 枠作成と同時に Zoom 会議を発行（設定済みのとき）。失敗は握りつぶし、予約時に再試行される。
    $slot = find_slot($id);
    if ($slot !== null) {
        ensure_slot_zoom($slot);
    }
    return $id;
}

/**
 * 枠に紐づく Zoom 会議を用意する。未生成かつ Zoom 設定済みなら会議を作成して
 * slots.zoom_meeting_id / zoom_url に保存し、その join_url を返す。
 * 既に発行済みならその URL を、Zoom 未設定・作成失敗なら null を返す（フォールバック）。
 */
function ensure_slot_zoom(array $slot): ?string
{
    $zoomUrl = $slot['zoom_url'] ?? null;
    if (is_string($zoomUrl) && $zoomUrl !== '') {
        return $zoomUrl; // 既に発行済み（重複作成を防ぐ）
    }
    if (!zoom_enabled()) {
        return null;
    }
    $slotId = (string) $slot['id'];

    // 手元の $slot は古い可能性がある。DB の最新値を再確認し、他の予約が既に発行済みなら
    // それを共有する（＝集団の説明会で申込者ごとに別会議が作られるのを防ぐ）。
    $cur = db()->prepare('SELECT zoom_url FROM slots WHERE id = ?');
    $cur->execute([$slotId]);
    $existing = $cur->fetchColumn();
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    $kind = (string) ($slot['kind'] ?? '');
    $duration = $kind === 'seminar' ? 40 : 30;
    $topic = $kind === 'seminar' ? 'Enlink 説明会' : 'Enlink 個別面談';
    $meeting = zoom_create_meeting($topic, (int) ($slot['start_at'] ?? time()), $duration);
    if ($meeting === null) {
        return null;
    }

    // 未発行のときだけ確定する（同時実行で二重発行されても、先に確定した1つだけを全員で共有）。
    $u = db()->prepare(
        "UPDATE slots SET zoom_meeting_id = ?, zoom_url = ? WHERE id = ? AND (zoom_url IS NULL OR zoom_url = '')"
    );
    $u->execute([$meeting['id'], $meeting['join_url'], $slotId]);
    if ($u->rowCount() === 0) {
        // 競合で他が先に確定 → 確定済み URL を採用（自分が作った会議は使わず、集団で1つに統一）。
        $cur->execute([$slotId]);
        $winner = $cur->fetchColumn();
        return is_string($winner) && $winner !== '' ? $winner : $meeting['join_url'];
    }
    return $meeting['join_url'];
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
 * Zoom はここでは一切生成しない。枠作成時（create_slot）に発行済みの URL を
 * そのまま予約者へ渡すだけ（説明会の申込ごとに個人会議が作られる事故を根絶）。
 * 枠に URL が無ければ zoom_url = null のまま確定し、手動 URL 案内にフォールバックする。
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

    // 枠作成時に発行済みの共有 URL を渡すだけ。予約時に Zoom 会議は生成しない。
    $zoomUrl = $slot['zoom_url'] ?? null;
    $zoomUrl = is_string($zoomUrl) && $zoomUrl !== '' ? $zoomUrl : null;

    $bookingId = generate_booking_id();
    $ins = db()->prepare(
        'INSERT INTO bookings (id, kind, line_user_id, member_id, slot_id, status, zoom_url, created_at)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $ins->execute([$bookingId, $kind, $lineUserId, $memberId, $slotId, 'booked', $zoomUrl, time()]);

    return ['booking_id' => $bookingId, 'zoom_url' => $zoomUrl];
}

/**
 * 枠の Zoom 会議を「強制的に」再発行する（既存 URL があっても新規作成する）。
 * 当日にリンクが壊れた等の復旧用。成功時は slots と当該枠の全予約(booked)の zoom_url を
 * 新 URL に更新し、新 URL を返す。Zoom 未設定・作成失敗なら null。
 */
function regenerate_slot_zoom(string $slotId): ?string
{
    $slot = find_slot($slotId);
    if ($slot === null || !zoom_enabled()) {
        return null;
    }
    $kind = (string) $slot['kind'];
    $duration = $kind === 'seminar' ? 40 : 30;
    $topic = $kind === 'seminar' ? 'Enlink 説明会' : 'Enlink 個別面談';
    $meeting = zoom_create_meeting($topic, (int) $slot['start_at'], $duration);
    if ($meeting === null) {
        return null;
    }
    db()->prepare('UPDATE slots SET zoom_meeting_id = ?, zoom_url = ? WHERE id = ?')
        ->execute([$meeting['id'], $meeting['join_url'], $slotId]);
    db()->prepare("UPDATE bookings SET zoom_url = ? WHERE slot_id = ? AND status = 'booked'")
        ->execute([$meeting['join_url'], $slotId]);
    return $meeting['join_url'];
}

/**
 * 枠の予約者（booked）へ LINE 通知するための line_user_id 一覧（重複排除）。
 * 予約に line_user_id が無い会員予約は members.line_user_id で補う。
 *
 * @return array<int,string>
 */
function slot_booking_line_users(string $slotId): array
{
    $stmt = db()->prepare(
        "SELECT COALESCE(b.line_user_id, m.line_user_id) AS uid
           FROM bookings b LEFT JOIN members m ON m.id = b.member_id
          WHERE b.slot_id = ? AND b.status = 'booked'"
    );
    $stmt->execute([$slotId]);
    $uids = [];
    foreach ($stmt->fetchAll() as $r) {
        $uid = $r['uid'] ?? null;
        if (is_string($uid) && $uid !== '') {
            $uids[$uid] = true;
        }
    }
    return array_keys($uids);
}

/**
 * 枠のZoom案内の「固定文面」を組み立てる（再送・友だち配信の添付で共通利用）。
 * $url を渡せばそれを、無ければ枠の zoom_url を使う。URLが無ければ日時のみ。
 */
function slot_zoom_notice_body(array $slot, ?string $url = null): string
{
    $label = ($slot['kind'] ?? '') === 'seminar' ? '説明会' : '個別面談';
    $when = date('Y-m-d H:i', (int) ($slot['start_at'] ?? 0) + 9 * 3600);
    $u = $url !== null ? $url : (string) ($slot['zoom_url'] ?? '');
    $t = "【{$label}】Zoom参加URLのご案内です。\n日時：{$when}（JST）";
    if ($u !== '') {
        $t .= "\n参加URL：{$u}";
    }
    return $t;
}

/**
 * 枠の申込者(booked)へ Zoom 参加URLを LINE 送信する。送信可能な宛先数と実送信数を返す。
 * 送信前に呼び出し側で「発行に成功していること」を確認すること。固定文面を使用。
 *
 * @return array{total:int, sent:int}
 */
function push_zoom_url_to_slot_bookings(string $slotId, string $url): array
{
    $slot = find_slot($slotId);
    if ($slot === null) {
        return ['total' => 0, 'sent' => 0];
    }
    $text = slot_zoom_notice_body($slot, $url);
    $uids = slot_booking_line_users($slotId);
    $sent = 0;
    foreach ($uids as $uid) {
        if (line_push($uid, [line_text($text)])) {
            $sent++;
        }
    }
    return ['total' => count($uids), 'sent' => $sent];
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
