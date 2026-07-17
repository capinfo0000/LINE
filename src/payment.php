<?php

/**
 * 入会金決済（買い切り・一回払い）と、入金完了→会員化＋ID/PW発行のプロビジョニング。
 *
 * 設計の要点（「払ったのに開かない」を絶対に起こさない）:
 *  - 会員化＋資格情報発行は Webhook 駆動。取りこぼしは照合cron（bin/reconcile.php）で救済する。
 *  - 二重プロビジョニング防止は「payments.stripe_checkout_session_id の UNIQUE 制約による claim-first」で担保。
 *    先に payments 行を INSERT OR IGNORE で確保し、勝った処理だけが資格情報を発行する。
 *  - 同一メールに既に有効会員が居れば新規発行せず紐付けのみ（重複アカウント防止）。
 */

declare(strict_types=1);

/** 入会金の金額（最小通貨単位＝円）。既定 2000。 */
function join_fee_amount(): int
{
    return max(1, (int) env('JOIN_FEE_AMOUNT', '2000'));
}

/** 入会金の通貨（JPY 固定）。 */
function join_fee_currency(): string
{
    return 'jpy';
}

/**
 * Stripe イベントIDを冪等記録する。初回なら true、既処理なら false。
 */
function record_stripe_event_once(string $eventId, string $type = ''): bool
{
    if ($eventId === '') {
        return true; // ID不明なら記録できないが処理は継続（下流の claim で守る）
    }
    $stmt = db()->prepare('INSERT OR IGNORE INTO stripe_events (event_id, type, processed_at) VALUES (?, ?, ?)');
    $stmt->execute([$eventId, $type, time()]);
    return $stmt->rowCount() > 0;
}

function find_payment_by_session(string $sessionId): ?array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE stripe_checkout_session_id = ?');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function generate_payment_id(): string
{
    return 'pay_' . bin2hex(random_bytes(6));
}

/**
 * 入金済み Checkout セッションから会員をプロビジョニングする（冪等）。
 * Webbook・照合cron の双方から呼ばれる。
 *
 * @param array{
 *   id:string, mode?:string, payment_status?:string,
 *   payment_intent?:string, customer?:string, amount_total?:int, currency?:string,
 *   email?:string, metadata?:array<string,string>
 * } $s 正規化済みのセッション情報
 * @return array{status:string, member_id:?string, issued:bool}
 *         status: 'done'（新規プロビジョニング）/ 'duplicate'（既処理）/ 'ignored'（対象外）
 */
function provision_member_from_checkout_session(array $s): array
{
    $sessionId = (string) ($s['id'] ?? '');
    if ($sessionId === '') {
        return ['status' => 'ignored', 'member_id' => null, 'issued' => false];
    }
    // 入会金決済（mode=payment）かつ支払い済みのみ対象。
    if (($s['mode'] ?? 'payment') !== 'payment') {
        return ['status' => 'ignored', 'member_id' => null, 'issued' => false];
    }
    if (($s['payment_status'] ?? 'paid') !== 'paid') {
        return ['status' => 'ignored', 'member_id' => null, 'issued' => false];
    }

    $meta = $s['metadata'] ?? [];
    $email = (string) ($s['email'] ?? ($meta['email'] ?? ''));
    $email = $email !== '' ? strtolower(trim($email)) : '';
    $lineUserId = (string) ($meta['line_user_id'] ?? '');
    $amount = (int) ($s['amount_total'] ?? join_fee_amount());
    $currency = (string) ($s['currency'] ?? join_fee_currency());
    $paymentIntent = (string) ($s['payment_intent'] ?? '');
    $customerId = (string) ($s['customer'] ?? '');

    // ---- claim-first: payments 行を確保できた処理だけが発行を行う ----
    $payId = generate_payment_id();
    $ins = db()->prepare(
        'INSERT OR IGNORE INTO payments
            (id, member_id, stripe_checkout_session_id, stripe_payment_intent_id, stripe_customer_id, email, amount, currency, status, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $ins->execute([$payId, null, $sessionId, $paymentIntent ?: null, $customerId ?: null, $email ?: null, $amount, $currency, 'processing', time()]);
    if ($ins->rowCount() === 0) {
        // 既に別処理（Webhook or cron）が claim 済み。
        $existing = find_payment_by_session($sessionId);
        return ['status' => 'duplicate', 'member_id' => $existing['member_id'] ?? null, 'issued' => false];
    }

    // ---- 会員の決定：既存(metadata.member_id / メール一致の有効会員) or 新規発行 ----
    $member = null;
    $issued = false;
    $loginId = null;
    $tempPassword = null;

    $metaMemberId = (string) ($meta['member_id'] ?? '');
    if ($metaMemberId !== '') {
        $member = find_member_by_id($metaMemberId);
    }
    if ($member === null && $email !== '') {
        $member = find_member_by_email($email);
    }

    if ($member === null) {
        // 新規会員を発行（active・初回PW強制変更）。
        $cred = issue_member_credentials($email !== '' ? $email : null, null, 'active', $lineUserId !== '' ? $lineUserId : null);
        $member = find_member_by_id($cred['member_id']);
        $loginId = $cred['login_id'];
        $tempPassword = $cred['temp_password'];
        $issued = true;
    } else {
        // 既存会員：有効化＋Stripe顧客の紐付け（必要なら）。
        activate_member($member['id'], $customerId ?: null, $lineUserId ?: null);
        $member = find_member_by_id($member['id']);
    }

    // ---- payments 行を確定（会員紐付け・paid） ----
    $upd = db()->prepare(
        'UPDATE payments SET member_id = ?, stripe_customer_id = COALESCE(?, stripe_customer_id), status = ?, paid_at = ? WHERE id = ?'
    );
    $upd->execute([$member['id'] ?? null, $customerId ?: null, 'paid', time(), $payId]);

    // 会員側にも Stripe 顧客IDを保存。
    if (($member['id'] ?? '') !== '' && $customerId !== '') {
        $sc = db()->prepare('UPDATE members SET stripe_customer_id = COALESCE(stripe_customer_id, ?) WHERE id = ?');
        $sc->execute([$customerId, $member['id']]);
    }

    // LINE 経由で来た相手なら、ファネルを paid にして会員IDを紐付ける（Bot配布の下準備）。
    if ($lineUserId !== '' && find_line_contact($lineUserId) !== null) {
        link_line_contact_member($lineUserId, (string) $member['id']);
        set_line_contact_state($lineUserId, 'paid');
    }

    audit_log('payment.provisioned', [
        'session' => substr($sessionId, 0, 24),
        'member'  => $member['id'] ?? '-',
        'issued'  => $issued ? 1 : 0,
    ]);

    // ---- 資格情報の配布（新規発行時のみ） ----
    if ($issued && $loginId !== null && $tempPassword !== null) {
        deliver_member_credentials($member, $loginId, $tempPassword);
    }

    return ['status' => 'done', 'member_id' => $member['id'] ?? null, 'issued' => $issued];
}

/**
 * 会員を有効化する（既存会員の入金確定時）。Stripe顧客・LINEユーザーの紐付けも行う。
 */
function activate_member(string $memberId, ?string $customerId, ?string $lineUserId): void
{
    $stmt = db()->prepare(
        "UPDATE members
            SET status = 'active',
                joined_at = COALESCE(joined_at, ?),
                stripe_customer_id = COALESCE(stripe_customer_id, ?),
                line_user_id = COALESCE(line_user_id, ?)
          WHERE id = ?"
    );
    $stmt->execute([time(), $customerId, $lineUserId, $memberId]);
}

/**
 * 発行した会員資格情報（ログインID＋仮パスワード）を配布する。
 *
 * Phase 2: 会員メールが分かればメール送付する。Phase 3 で公式LINE Bot 配信を追加/優先する。
 * ※ 仮パスワードは平文で保存しない。この関数内で配布に使い切る（呼び出し側も保持しない）。
 *
 * @return bool 何らかの経路で配布できたら true
 */
function deliver_member_credentials(array $member, string $loginId, string $tempPassword): bool
{
    $delivered = false;
    $email = (string) ($member['email'] ?? '');
    $lineUserId = (string) ($member['line_user_id'] ?? '');
    $loginUrl = base_url() . '/member/login.php';
    $openChatUrl = active_openchat_url();
    $viaLine = false;

    // 公式LINE Bot（Push）で ID/PW＋OpenChat URL を1通にまとめて配信（通数節約）。
    // 二重配布は line_contacts.credentials_sent の claim で防ぐ。
    if ($lineUserId !== '' && find_line_contact($lineUserId) !== null && claim_credentials_send($lineUserId)) {
        $text = "ご入会ありがとうございます。会員サイトのログイン情報をお送りします。\n\n"
            . "ログインURL: {$loginUrl}\n"
            . "ログインID: {$loginId}\n"
            . "仮パスワード: {$tempPassword}\n\n"
            . "初回ログイン時に、ご自身のパスワードへ変更してください。";
        if ($openChatUrl !== null) {
            $text .= "\n\n■ 交流用オープンチャットはこちら\n{$openChatUrl}";
        }
        $text .= "\n\n※この情報は第三者に共有しないでください。";
        $viaLine = line_push($lineUserId, [line_text($text)]);
        $delivered = $viaLine || $delivered;
    }

    // メールが分かれば併せて送付（LINE配布に失敗した場合の保険にもなる）。
    if ($email !== '') {
        $body = "ご入会ありがとうございます。会員サイトのログイン情報をお送りします。\n\n"
            . "ログインURL: {$loginUrl}\n"
            . "ログインID : {$loginId}\n"
            . "仮パスワード: {$tempPassword}\n\n"
            . "はじめてのログイン時に、ご自身のパスワードへの変更をお願いします。\n"
            . "このメールは大切に保管し、第三者に共有しないでください。\n";
        $delivered = send_mail($email, '【AKマッチング】会員サイトのログイン情報', $body) || $delivered;
    }

    audit_log('credentials.delivered', [
        'member' => $member['id'] ?? '-',
        'via_email' => $email !== '' ? 1 : 0,
        'via_line' => $viaLine ? 1 : 0,
    ]);
    return $delivered;
}

/**
 * Checkout セッションのオブジェクト（SDK / 配列）を provision 用の配列に正規化する。
 */
function normalize_checkout_session($session): array
{
    $get = static function ($obj, string $key, $default = null) {
        if (is_array($obj)) {
            return $obj[$key] ?? $default;
        }
        return $obj->$key ?? $default;
    };
    $metaRaw = $get($session, 'metadata', []);
    $meta = [];
    if (is_array($metaRaw)) {
        $meta = $metaRaw;
    } elseif (is_object($metaRaw)) {
        foreach ($metaRaw as $k => $v) {
            $meta[$k] = (string) $v;
        }
    }
    $custDetails = $get($session, 'customer_details', null);
    $email = $custDetails ? $get($custDetails, 'email', '') : '';

    return [
        'id'             => (string) $get($session, 'id', ''),
        'mode'           => (string) $get($session, 'mode', 'payment'),
        'payment_status' => (string) $get($session, 'payment_status', ''),
        'payment_intent' => (string) $get($session, 'payment_intent', ''),
        'customer'       => (string) $get($session, 'customer', ''),
        'amount_total'   => (int) $get($session, 'amount_total', 0),
        'currency'       => (string) $get($session, 'currency', 'jpy'),
        'email'          => (string) ($email ?: ''),
        'metadata'       => $meta,
    ];
}
