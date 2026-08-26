<?php

/**
 * 公式LINE（Messaging API）Bot ヘルパー。
 *
 * - Webhook 署名検証（X-Line-Signature = HMAC-SHA256(body, channel_secret) の base64）
 * - reply（無料）／push（課金）の送信と通数記録（line_messages）
 * - 友だち（line_contacts）のオンボーディング・ファネル状態管理
 *
 * トークン未設定（ローカル/テスト）でも例外を投げず、送信は no-op（記録のみ）にする。
 */

declare(strict_types=1);

const LINE_REPLY_ENDPOINT = 'https://api.line.me/v2/bot/message/reply';
const LINE_PUSH_ENDPOINT  = 'https://api.line.me/v2/bot/message/push';

function line_channel_secret(): ?string
{
    return env('LINE_CHANNEL_SECRET');
}

function line_channel_token(): ?string
{
    return env('LINE_CHANNEL_ACCESS_TOKEN');
}

/**
 * Webhook 署名を検証する。secret 未設定なら false（＝拒否）。
 */
function line_verify_signature(string $body, string $signature): bool
{
    $secret = line_channel_secret();
    if ($secret === null || $signature === '') {
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
    return hash_equals($expected, $signature);
}

/** テキストメッセージ・オブジェクトを作る。 */
function line_text(string $text): array
{
    return ['type' => 'text', 'text' => $text];
}

/**
 * LINE API へ JSON POST（内部ヘルパー）。トークン無しなら送らず false。
 */
function line_api_post(string $url, array $payload): bool
{
    $token = line_channel_token();
    if ($token === null) {
        return false; // 未設定環境では送信しない（記録は呼び出し側で行う）
    }
    if (!function_exists('curl_init')) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($err !== 0 || $code < 200 || $code >= 300) {
        error_log("LINE API error url={$url} code={$code} err={$err} resp=" . substr((string) $resp, 0, 300));
        return false;
    }
    return true;
}

/** 送受信を line_messages に記録する（通数コスト把握用）。 */
function line_log_message(?string $userId, string $direction, string $channel, string $type, bool $billable): void
{
    $stmt = db()->prepare(
        'INSERT INTO line_messages (line_user_id, direction, channel, type, billable, created_at) VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([$userId, $direction, $channel, $type, $billable ? 1 : 0, time()]);
}

/**
 * 応答メッセージ（reply）。ユーザー操作への返信で無料。
 *
 * @param array<int,array> $messages
 */
function line_reply(string $replyToken, array $messages): bool
{
    $ok = line_api_post(LINE_REPLY_ENDPOINT, ['replyToken' => $replyToken, 'messages' => array_values($messages)]);
    line_log_message(null, 'out', 'reply', 'text', false);
    return $ok;
}

/**
 * 送信メッセージ（push）。時間差・イベント起点の配信で1通ごとに課金。
 *
 * @param array<int,array> $messages
 */
function line_push(string $userId, array $messages): bool
{
    $ok = line_api_post(LINE_PUSH_ENDPOINT, ['to' => $userId, 'messages' => array_values($messages)]);
    // push は宛先1件ごとに課金。メッセージ数ではなくリクエスト単位で1通計上。
    line_log_message($userId, 'out', 'push', 'text', true);
    return $ok;
}

/**
 * LINEプロフィール（表示名・アイコン）を取得する。取得できなければ null。
 * GET https://api.line.me/v2/bot/profile/{userId}
 * ※ userId が現在のチャネルの友だちでない（旧チャネル等）場合は 404 で null。
 */
function line_get_profile_ex(string $userId): array
{
    $token = line_channel_token();
    if ($token === null || $userId === '' || !function_exists('curl_init')) {
        return ['code' => 0, 'data' => null];
    }
    $ch = curl_init('https://api.line.me/v2/bot/profile/' . rawurlencode($userId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = null;
    if ($code >= 200 && $code < 300 && is_string($resp)) {
        $decoded = json_decode($resp, true);
        $data = is_array($decoded) ? $decoded : null;
    }
    return ['code' => $code, 'data' => $data];
}

function line_get_profile(string $userId): ?array
{
    return line_get_profile_ex($userId)['data'];
}

/** 連絡先の非表示フラグを設定する。 */
function set_line_contact_hidden(string $userId, bool $hidden): void
{
    db()->prepare('UPDATE line_contacts SET hidden = ?, updated_at = ? WHERE line_user_id = ?')
        ->execute([$hidden ? 1 : 0, time(), $userId]);
}

/**
 * LINE連絡先を手動で完全削除する（旧LINEの不達連絡先の掃除用）。
 * 連絡先レコード・送受信ログ・保留キューを削除し、会員が紐付いていれば
 * その会員の line_user_id を解除する（会員アカウント自体は削除しない）。
 * @return bool 削除できたら true
 */
function delete_line_contact(string $userId): bool
{
    if ($userId === '') {
        return false;
    }
    // 会員紐付けの解除（会員は残す）。
    db()->prepare('UPDATE members SET line_user_id = NULL WHERE line_user_id = ?')->execute([$userId]);
    // 付随データの掃除（存在するものだけ・冪等）。
    foreach (['line_messages', 'slot_url_pending', 'bookings'] as $tbl) {
        try {
            db()->prepare("DELETE FROM {$tbl} WHERE line_user_id = ?")->execute([$userId]);
        } catch (\Throwable $e) {
            // 列やテーブルが無い場合は無視。
        }
    }
    $stmt = db()->prepare('DELETE FROM line_contacts WHERE line_user_id = ?');
    $stmt->execute([$userId]);
    audit_log('line.contact_deleted', ['line' => substr($userId, 0, 10)]);
    return $stmt->rowCount() > 0;
}

/** 非表示の連絡先をすべて表示に戻す。 */
function unhide_all_contacts(): int
{
    $stmt = db()->query('UPDATE line_contacts SET hidden = 0 WHERE hidden = 1');
    return $stmt->rowCount();
}

/**
 * 現チャネルで「取得不可（404/400）」の連絡先を隠す（＝旧チャネル・削除済みの旧データ）。
 * 取得できた場合は表示名も最新化する（ついでの補完）。
 * ネットワーク一時エラー等（0/5xx）は誤って隠さないようスキップする。
 *
 * @return int 隠した件数
 */
function hide_unreachable_contacts(int $limit = 300): int
{
    if (line_channel_token() === null) {
        return 0;
    }
    $rows = db()->query('SELECT line_user_id FROM line_contacts WHERE hidden = 0 LIMIT ' . max(1, (int) $limit))->fetchAll();
    $hidden = 0;
    foreach ($rows as $r) {
        $uid = (string) $r['line_user_id'];
        $res = line_get_profile_ex($uid);
        $code = (int) $res['code'];
        if ($code === 404 || $code === 400) {
            set_line_contact_hidden($uid, true); // 現チャネルに存在しない＝旧データ
            $hidden++;
        } elseif ($res['data'] !== null && ($res['data']['displayName'] ?? '') !== '') {
            db()->prepare('UPDATE line_contacts SET display_name = ?, updated_at = ? WHERE line_user_id = ?')
                ->execute([mb_substr((string) $res['data']['displayName'], 0, 100), time(), $uid]);
        }
    }
    return $hidden;
}

/**
 * 表示名の無い友だちに、LINEプロフィールAPIで名前を補完する（既存データの後追い）。
 * 旧チャネルのuserId等、現チャネルで取得できないものはスキップ（そのまま残る）。
 *
 * @return int 補完できた件数
 */
function line_backfill_contact_names(int $limit = 30): int
{
    if (line_channel_token() === null) {
        return 0;
    }
    $rows = db()->query(
        "SELECT line_user_id FROM line_contacts WHERE display_name IS NULL OR display_name = '' LIMIT " . max(1, (int) $limit)
    )->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        $prof = line_get_profile((string) $r['line_user_id']);
        $name = $prof !== null ? (string) ($prof['displayName'] ?? '') : '';
        if ($name !== '') {
            db()->prepare('UPDATE line_contacts SET display_name = ?, updated_at = ? WHERE line_user_id = ?')
                ->execute([mb_substr($name, 0, 100), time(), $r['line_user_id']]);
            $n++;
        }
    }
    return $n;
}

/* ------------------------- ファネル状態（line_contacts） ------------------------- */

/** 友だちレコードを取得（無ければ null）。 */
function find_line_contact(string $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM line_contacts WHERE line_user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 友だちレコードを取得（無ければ added 状態で作成）。 */
function get_or_create_line_contact(string $userId, ?string $displayName = null): array
{
    $c = find_line_contact($userId);
    // 表示名が渡されておらず、名前が空（新規 or 未取得）なら、LINEプロフィールから名前を取得。
    if ($displayName === null && ($c === null || ($c['display_name'] ?? '') === '')) {
        $prof = line_get_profile($userId);
        if ($prof !== null && ($prof['displayName'] ?? '') !== '') {
            $displayName = mb_substr((string) $prof['displayName'], 0, 100);
        }
    }
    if ($c !== null) {
        if ($displayName !== null && ($c['display_name'] ?? '') === '') {
            $u = db()->prepare('UPDATE line_contacts SET display_name = ?, updated_at = ? WHERE line_user_id = ?');
            $u->execute([$displayName, time(), $userId]);
            $c['display_name'] = $displayName;
        }
        return $c;
    }
    $stmt = db()->prepare(
        'INSERT INTO line_contacts (line_user_id, display_name, onboarding_state, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $displayName, 'added', time(), time()]);
    return find_line_contact($userId);
}

/** 有効なファネル状態の一覧（順序）。 */
function line_onboarding_states(): array
{
    return ['added', 'booked_seminar', 'seminar_done', 'booked_interview', 'interview_done', 'approved', 'payment_sent', 'paid'];
}

/** ファネル状態を更新する。 */
function set_line_contact_state(string $userId, string $state): void
{
    $stmt = db()->prepare('UPDATE line_contacts SET onboarding_state = ?, updated_at = ? WHERE line_user_id = ?');
    $stmt->execute([$state, time(), $userId]);
}

/** 加入承認フラグを設定する（決済リンク送信の任意ゲート）。 */
function set_line_contact_approved(string $userId, bool $approved): void
{
    $stmt = db()->prepare('UPDATE line_contacts SET approved = ?, updated_at = ? WHERE line_user_id = ?');
    $stmt->execute([$approved ? 1 : 0, time(), $userId]);
}

/** 面談〜決済で取得したメールを保存する。 */
function set_line_contact_email(string $userId, string $email): void
{
    $email = strtolower(trim($email));
    $stmt = db()->prepare('UPDATE line_contacts SET email = ?, updated_at = ? WHERE line_user_id = ?');
    $stmt->execute([$email !== '' ? $email : null, time(), $userId]);
}

/** 会員IDを紐付ける（入金プロビジョニング後）。 */
function link_line_contact_member(string $userId, string $memberId): void
{
    $stmt = db()->prepare('UPDATE line_contacts SET member_id = ?, updated_at = ? WHERE line_user_id = ?');
    $stmt->execute([$memberId, time(), $userId]);
}

/**
 * 資格情報配布の冪等マーク。既に送信済みなら false（＝二重配布を防ぐ）。
 * 未送信なら 1 に立てて true を返す（アトミックな claim）。
 */
function claim_credentials_send(string $userId): bool
{
    $stmt = db()->prepare('UPDATE line_contacts SET credentials_sent = 1, updated_at = ? WHERE line_user_id = ? AND credentials_sent = 0');
    $stmt->execute([time(), $userId]);
    return $stmt->rowCount() > 0;
}

/* ------------------------- メッセージ組み立て ------------------------- */

/** UNIX秒を JST の短い表記（例 3/5(水) 19:00）にする。 */
function line_jst_label(int $ts): string
{
    $w = ['日', '月', '火', '水', '木', '金', '土'];
    return date('n/j', $ts) . '(' . $w[(int) date('w', $ts)] . ') ' . date('H:i', $ts);
}

/**
 * クイックリプライ（postback ボタン）付きテキストメッセージ。
 *
 * @param array<int,array{label:string,data:string}> $items 最大13件
 */
function line_text_quickreply(string $text, array $items): array
{
    $qr = [];
    foreach (array_slice($items, 0, 13) as $it) {
        $qr[] = [
            'type' => 'action',
            'action' => [
                'type' => 'postback',
                'label' => mb_substr($it['label'], 0, 20),
                'data' => $it['data'],
                'displayText' => $it['label'],
            ],
        ];
    }
    $msg = ['type' => 'text', 'text' => $text];
    if ($qr !== []) {
        $msg['quickReply'] = ['items' => $qr];
    }
    return $msg;
}

/** 予約枠一覧をクイックリプライにする。空なら案内テキストのみ。 */
/**
 * 初回（友だち追加時）のオンボーディング・メッセージ配列。
 * follow時の自動送信と、管理画面からの手動送信の両方で共通利用する。
 * あいさつ＋説明会の日程（予約用クイックリプライ）を返す。
 *
 * @return array<int,array>
 */
/**
 * 登録運用モード。
 *  - 'auto'  ：初期運用。友だち追加で即・無料会員を発行し、ID/PWをLINE送信。
 *  - 'normal'：通常運用。説明会 → 希望者は決済 → ログイン情報送信（従来フロー）。
 * 管理画面から切り替え可能。既定は 'auto'（初期フェーズ）。
 */
function signup_mode(): string
{
    return app_setting_get('signup_mode', 'auto') === 'normal' ? 'normal' : 'auto';
}

/** 登録運用モードを設定する（'auto' | 'normal'）。 */
function set_signup_mode(string $mode): void
{
    app_setting_set('signup_mode', $mode === 'normal' ? 'normal' : 'auto');
}

/**
 * 友だち追加のときに送る本文（概要説明＋ログイン情報）。
 *
 * 1通にまとめているのは、これを返信（reply）で返せば公式LINEの無料枠を
 * 消費しないため。push で別送りにすると、友だち1人につき1通ずつ減る。
 *
 * 人数・金額・URLはすべて設定から引く。べた書きすると、料金やしきい値を
 * 変えたときに本文だけ古い数字のまま残る。
 *
 * @param ?string $loginId      発行できた場合のログインID。null ならログイン情報の段落を出さない
 * @param ?string $tempPassword 同上（仮パスワード）
 */
function line_welcome_text(?string $loginId, ?string $tempPassword): string
{
    $base = rtrim(base_url(), '/');
    $t = "ご登録ありがとうございます。Enlink（縁リンク）です。\n\n"
        . "Enlinkは「提供できること」と「求めていること」が噛み合う相手だけに出会える、"
        . "会員制のビジネスマッチングです。交流会のように総当たりで名刺を配る必要がありません。";

    // ---- ログイン情報 ----
    if ($loginId !== null && $tempPassword !== null) {
        $t .= "\n\n■ 会員サイトのログイン情報\n"
            . "ログインURL: {$base}/\n"
            . "ログインID: {$loginId}\n"
            . "仮パスワード: {$tempPassword}\n"
            . "※この情報は第三者に共有しないでください。";
    } else {
        // すでにご自身のパスワードをお使いの方。作り直すと今のパスワードが使えなくなる。
        $t .= "\n\n■ 会員サイト\n{$base}/ からログインしてご利用ください。";
    }

    // ---- はじめにやること ----
    $t .= "\n\n■ はじめにやること\n";
    if ($loginId !== null) {
        $t .= "1. 上のURLからログイン\n2. ご自身のパスワードへ変更\n3. プロフィールと自己紹介を入力";
    } else {
        $t .= "1. 上のURLからログイン\n2. プロフィールと自己紹介を入力";
    }
    if (intro_gate_enabled()) {
        $t .= "\n   （自己紹介を入れると「さがす」が使えるようになります）";
    }

    // ---- 料金 ----
    $fee = monthly_fee_text();
    $min = referral_waiver_min();
    $t .= "\n\n■ 料金について\n";
    if (billing_started()) {
        $t .= "会員機能のご利用には月額会費（{$fee}）が必要です。";
    } elseif (billing_grace_active()) {
        $startsAt = billing_starts_at();
        $t .= date('n月j日', (int) $startsAt) . "から月額会費（{$fee}）が始まります。それまでは無料でご利用いただけます。";
    } else {
        $t .= "いまは無料でご利用いただけます。"
            . "会員数が" . billing_free_limit() . "名を超えた翌月から月額会費（{$fee}）が始まります。";
    }
    $t .= "\nご紹介した{$min}名が会費を登録されると、あなたの会費は無料になります。\n"
        . "詳しくは {$base}/pricing";

    // ---- 説明会（希望者のみ）----
    $t .= "\n\n■ 説明会について（ご希望の方のみ）\n"
        . "使い方の説明会（Zoom・約30分）をご希望の方は、"
        . "このトークに「説明会」とお送りください。日程をご案内します。";

    // ---- 交流用オープンチャット（未登録なら段落ごと出さない）----
    $openChatUrl = active_openchat_url();
    if ($openChatUrl !== null) {
        $t .= "\n\n■ 交流用オープンチャット\n{$openChatUrl}";
    }

    $t .= "\n\nご不明点はこのトークからお気軽にどうぞ。";
    return $t;
}

/**
 * 初期運用（auto）の友だち追加時メッセージ。
 *
 * その場で会員を発行し、概要説明とログイン情報を1通にまとめて返す。
 * ここで返したものは webhook が reply で送るので、公式LINEの無料枠を消費しない。
 * そのため provision には $deliver = false を渡し、別途 push させない。
 */
function line_auto_signup_messages(string $userId): array
{
    $r = provision_free_member_from_contact($userId, false);
    if (($r['status'] ?? '') === 'done' || ($r['status'] ?? '') === 'linked') {
        return [line_text(line_welcome_text($r['login_id'] ?? null, $r['temp_password'] ?? null))];
    }
    // 発行に失敗した場合は従来の案内にフォールバック。
    return line_onboarding_messages();
}

function line_onboarding_messages(): array
{
    $greeting = "友だち追加ありがとうございます。Enlinkです。\n\n"
        . "Enlinkは、審査を経た会員だけが集まる、会員制の人脈マッチングサービスです。条件に合う相手を検索・おすすめから見つけてつながれます。\n\n"
        . "まずは無料の説明会（Zoom・約30分）で仕組みをご案内します。";
    return [
        line_text($greeting),
        line_slots_message('seminar', 'ご希望の説明会の日程をお選びください。'),
    ];
}

/**
 * 予約可能な枠を「カード型（Flexカルーセル）」で返す。各カードに「この日程を選ぶ」ボタン付き。
 * $kinds に複数種別を渡すと、説明会・個別面談をまとめて1つのカルーセルにする。
 */
function line_slots_flex(array $kinds, string $altText = 'ご予約日程のご案内'): array
{
    $bubbles = [];
    foreach ($kinds as $kind) {
        $kind = $kind === 'interview' ? 'interview' : 'seminar';
        $label = $kind === 'seminar' ? '説明会' : '個別面談';
        foreach (open_slots($kind, 12) as $s) {
            if (count($bubbles) >= 12) {
                break 2; // カルーセルは最大12枚
            }
            $when = line_jst_label((int) $s['start_at']);
            $remain = max(0, (int) $s['capacity'] - (int) $s['booked_count']);
            $bubbles[] = [
                'type' => 'bubble', 'size' => 'kilo',
                'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'contents' => [
                    ['type' => 'text', 'text' => $label, 'size' => 'sm', 'weight' => 'bold', 'color' => '#2563eb'],
                    ['type' => 'text', 'text' => $when, 'size' => 'lg', 'weight' => 'bold', 'wrap' => true],
                    ['type' => 'text', 'text' => '残り ' . $remain . ' 席', 'size' => 'sm', 'color' => '#6b7280'],
                ]],
                'footer' => ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                    ['type' => 'button', 'style' => 'primary', 'color' => '#2563eb', 'height' => 'sm',
                     'action' => ['type' => 'postback', 'label' => 'この日程を選ぶ', 'data' => 'pick:' . $kind . ':' . $s['id'], 'displayText' => $when . ' を選択']],
                ]],
            ];
        }
    }
    if ($bubbles === []) {
        return line_text('現在ご案内できる日程がありません。恐れ入りますが、日程が公開されるまでお待ちください。');
    }
    return ['type' => 'flex', 'altText' => $altText, 'contents' => ['type' => 'carousel', 'contents' => $bubbles]];
}

/** 単一種別の日程メッセージ（カード型）。既存呼び出しの互換用。 */
function line_slots_message(string $kind, string $prompt): array
{
    return line_slots_flex([$kind], $prompt);
}

/** 予約確認カード（「この日程を選ぶ」後に表示）。はい→book / やめる→cancel。 */
function line_booking_confirm(string $kind, string $slotId): array
{
    $slot = find_slot($slotId);
    if ($slot === null) {
        return line_text('恐れ入りますが、その日程は見つかりませんでした。もう一度お選びください。');
    }
    $kind = $kind === 'interview' ? 'interview' : 'seminar';
    $label = $kind === 'seminar' ? '説明会' : '個別面談';
    $when = line_jst_label((int) $slot['start_at']);
    return [
        'type' => 'flex', 'altText' => '予約の確認',
        'contents' => [
            'type' => 'bubble',
            'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'md', 'contents' => [
                ['type' => 'text', 'text' => '【予約の確認】', 'weight' => 'bold'],
                ['type' => 'text', 'text' => $label . "\n" . $when, 'weight' => 'bold', 'size' => 'lg', 'wrap' => true],
                ['type' => 'text', 'text' => 'この日程で予約しますか？', 'size' => 'sm', 'color' => '#6b7280'],
            ]],
            'footer' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'contents' => [
                ['type' => 'button', 'style' => 'primary', 'color' => '#2563eb', 'height' => 'sm',
                 'action' => ['type' => 'postback', 'label' => 'はい、予約する', 'data' => 'book:' . $kind . ':' . $slotId, 'displayText' => 'はい、予約する']],
                ['type' => 'button', 'style' => 'secondary', 'height' => 'sm',
                 'action' => ['type' => 'postback', 'label' => 'やめる', 'data' => 'cancel', 'displayText' => 'やめる']],
            ]],
        ],
    ];
}

/* ------------------------- 決済リンク配信（承認後） ------------------------- */


/* ------------------------- ファネル状態機械（Webhookイベント処理） ------------------------- */

/**
 * 1つの LINE Webhook イベントを処理し、返信メッセージ配列を返す（reply 用・無料）。
 * 状態遷移（line_contacts）を副作用として行う。純粋に近い形にして単体テスト可能にする。
 *
 * @return array<int,array> reply する message オブジェクト（空なら返信しない）
 */
function line_handle_event(array $event): array
{
    $type = (string) ($event['type'] ?? '');
    $userId = (string) ($event['source']['userId'] ?? '');
    if ($userId === '') {
        return [];
    }
    line_log_message($userId, 'in', $type === 'postback' ? 'postback' : 'message', $type, false);

    if ($type === 'follow') {
        get_or_create_line_contact($userId); // 表示名も取得（管理での個別対応用）
        set_line_contact_state($userId, 'added');
        // 初期運用：即・無料会員を発行してID/PWを送信。通常運用：説明会案内。
        if (signup_mode() === 'auto') {
            return line_auto_signup_messages($userId);
        }
        return line_onboarding_messages();
    }

    if ($type === 'message') {
        get_or_create_line_contact($userId);
        $mtype = (string) ($event['message']['type'] ?? '');
        $text = trim((string) ($event['message']['text'] ?? ''));

        // 会員本人が公式LINEに自己紹介を送ったら、さがすの閲覧ロックを解除する。
        // 判定：ひな形の目印「enlink」を含む or 十分な長さ（予約キーワードは除外）。
        $contact = find_line_contact($userId);
        $linkedMemberId = $contact !== null ? (string) ($contact['member_id'] ?? '') : '';
        // 会員の特定：連絡先の紐付け → 無ければ members.line_user_id から直接引く。
        // （紐付け漏れで永久ロックになるのを防ぐ）
        $member = $linkedMemberId !== '' ? find_member_by_id($linkedMemberId) : null;
        if ($member === null) {
            $member = find_member_by_line_user_id($userId);
        }
        // 単独のメールアドレスは自己紹介ではなくメール登録として扱うため除外する。
        if ($mtype === 'text' && $member !== null && !filter_var($text, FILTER_VALIDATE_EMAIL)) {
            if (!member_intro_submitted($member) && member_needs_intro($member)) {
                // 予約系キーワードだけは自己紹介として扱わない。
                $isReserved = mb_strpos($text, '説明会') !== false
                    || mb_strpos($text, 'セミナー') !== false
                    || mb_strpos($text, '面談') !== false;
                // 目印「enlink」、複数行（ひな形は複数行）、または10文字以上を自己紹介とみなす。
                $looksIntro = !$isReserved && (
                    mb_stripos($text, 'enlink') !== false
                    || mb_strpos($text, "\n") !== false
                    || mb_strlen($text) >= 10
                );
                if ($looksIntro) {
                    $mid = (string) $member['id'];
                    mark_intro_submitted($mid);
                    // 連絡先の紐付けが抜けていれば、ここで補完しておく。
                    if ($linkedMemberId === '' && $contact !== null) {
                        link_line_contact_member($userId, $mid);
                    }
                    return [line_text("自己紹介を受け付けました。ありがとうございます。\n会員サイトの「さがす」がご利用いただけるようになりました。")];
                }
            }
        }

        // メールアドレスらしき入力は面談段階のメール登録として扱う。
        if ($mtype === 'text' && filter_var($text, FILTER_VALIDATE_EMAIL)) {
            set_line_contact_email($userId, $text);
            return [line_text('メールアドレスを承りました。ありがとうございます。担当より個別面談・お手続きのご案内をいたします。')];
        }
        if ($mtype === 'text' && (mb_strpos($text, '説明会') !== false || mb_strpos($text, 'セミナー') !== false)) {
            return [line_slots_message('seminar', 'ご希望の説明会の日程をお選びください。')];
        }
        if ($mtype === 'text' && mb_strpos($text, '面談') !== false) {
            return [line_slots_message('interview', 'ご希望の個別面談の日程をお選びください。')];
        }
        // 既定の案内
        return [line_text_quickreply(
            'ご案内メニューです。ご希望の項目をお選びください。',
            [
                ['label' => '説明会を予約', 'data' => 'show:seminar'],
                ['label' => '個別面談を予約', 'data' => 'show:interview'],
            ]
        )];
    }

    if ($type === 'postback') {
        get_or_create_line_contact($userId);
        $data = (string) ($event['postback']['data'] ?? '');
        $parts = explode(':', $data);
        $action = $parts[0] ?? '';

        if ($action === 'show') {
            $kind = ($parts[1] ?? '') === 'interview' ? 'interview' : 'seminar';
            $prompt = $kind === 'seminar' ? 'ご希望の説明会の日程をお選びください。' : 'ご希望の個別面談の日程をお選びください。';
            return [line_slots_message($kind, $prompt)];
        }

        if ($action === 'pick') {
            // 「この日程を選ぶ」→ 予約確認カードを表示（確定は「はい、予約する」）。
            $kind = ($parts[1] ?? '') === 'interview' ? 'interview' : 'seminar';
            $slotId = $parts[2] ?? '';
            return [line_booking_confirm($kind, $slotId)];
        }

        if ($action === 'cancel') {
            return [line_text('承知しました。ご希望の日程が決まりましたら、いつでもお選びください。')];
        }

        if ($action === 'book') {
            $kind = ($parts[1] ?? '') === 'interview' ? 'interview' : 'seminar';
            $slotId = $parts[2] ?? '';
            // 二重予約は「満席」ではないので、理由を分けて伝える。
            if (already_booked($slotId, $userId, null)) {
                $booked = find_slot($slotId);
                $label = $kind === 'seminar' ? '説明会' : '個別面談';
                $whenBooked = $booked !== null ? line_jst_label((int) $booked['start_at']) : '';
                return [line_text("この{$label}はすでにご予約済みです。\n日時：{$whenBooked}\n変更・取消をご希望の場合は、このトークにご連絡ください。")];
            }
            $result = book_slot($slotId, $kind, $userId, null);
            if ($result === null) {
                return [line_text('申し訳ありません。その枠は満席または受付終了です。別の日程をお選びください。'), line_slots_message($kind, 'ほかの日程はこちらです。')];
            }
            $slot = find_slot($slotId);
            $when = $slot !== null ? line_jst_label((int) $slot['start_at']) : '';
            set_line_contact_state($userId, $kind === 'seminar' ? 'booked_seminar' : 'booked_interview');

            if ($kind === 'seminar') {
                // 集団の説明会：その説明会（枠）の共有 Zoom URL だけを短く送る。
                // URL は枠作成時に1回だけ発行され、全申込者が同じものを受け取る（個別会議は作らない）。
                if (!empty($result['zoom_url'])) {
                    return [line_text("説明会のZoom URLです。\n日時：{$when}\n{$result['zoom_url']}")];
                }
                return [line_text("説明会のご予約を承りました。\n日時：{$when}\nZoom URLは追ってご案内します。")];
            }

            // 個別面談：従来どおりの案内。
            $msg = "個別面談のご予約を承りました。\n日時：{$when}\n";
            if (!empty($result['zoom_url'])) {
                $msg .= "参加URL：{$result['zoom_url']}\n";
            } else {
                $msg .= "参加URLは追ってご案内します。\n";
            }
            $msg .= "開始前にリマインドをお送りします。";
            return [line_text($msg)];
        }

        return [line_text('操作を受け付けられませんでした。もう一度お試しください。')];
    }

    return [];
}
