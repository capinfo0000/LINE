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
    $jst = $ts + 9 * 3600;
    return gmdate('n/j', $jst) . '(' . $w[(int) gmdate('w', $jst)] . ') ' . gmdate('H:i', $jst);
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
function line_onboarding_messages(): array
{
    $greeting = "友だち追加ありがとうございます！Enlinkです😊\n\n"
        . "Enlinkは、審査を経た会員だけが集まる、会員制の人脈マッチングサービスです。条件に合う相手を検索・おすすめから見つけてつながれます。\n\n"
        . "まずは無料の説明会（Zoom・約30分）で仕組みをご案内します。";
    return [
        line_text($greeting),
        line_slots_message('seminar', 'ご希望の説明会の日程をお選びください。'),
    ];
}

function line_slots_message(string $kind, string $prompt): array
{
    $slots = open_slots($kind, 13);
    if ($slots === []) {
        return line_text('現在ご案内できる日程がありません。恐れ入りますが、日程が公開されるまでお待ちください。');
    }
    $items = [];
    foreach ($slots as $s) {
        $items[] = ['label' => line_jst_label((int) $s['start_at']), 'data' => 'book:' . $kind . ':' . $s['id']];
    }
    return line_text_quickreply($prompt, $items);
}

/* ------------------------- 決済リンク配信（承認後） ------------------------- */

/**
 * 承認済みの相手に入会金の決済リンクを Push する（任意の承認ゲート）。
 * 未承認・連絡先不明なら false。
 */
function send_payment_link_to_contact(string $userId): bool
{
    $c = find_line_contact($userId);
    if ($c === null) {
        return false;
    }
    if ((int) ($c['approved'] ?? 0) !== 1) {
        return false; // 承認ゲート
    }
    $url = base_url() . '/checkout.php?lu=' . rawurlencode($userId);
    if (!empty($c['email'])) {
        $url .= '&email=' . rawurlencode((string) $c['email']);
    }
    $amount = format_amount(join_fee_amount());
    $text = "ご入会手続きのご案内です。\n\n"
        . "下記より入会金（{$amount}・買い切り）のお支払いをお願いします。\n{$url}\n\n"
        . "お支払い完了後、会員サイトのログイン情報をこのトークにお送りします。";
    $ok = line_push($userId, [line_text($text)]);
    set_line_contact_state($userId, 'payment_sent');
    return $ok;
}

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
        get_or_create_line_contact($userId);
        set_line_contact_state($userId, 'added');
        return line_onboarding_messages();
    }

    if ($type === 'message') {
        get_or_create_line_contact($userId);
        $mtype = (string) ($event['message']['type'] ?? '');
        $text = trim((string) ($event['message']['text'] ?? ''));

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

        if ($action === 'book') {
            $kind = ($parts[1] ?? '') === 'interview' ? 'interview' : 'seminar';
            $slotId = $parts[2] ?? '';
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
