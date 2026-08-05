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
    // 買い切り(mode=payment) または サブスク(mode=subscription) の支払い済みを対象。
    $mode = (string) ($s['mode'] ?? 'payment');
    if ($mode !== 'payment' && $mode !== 'subscription') {
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
    // サブスクなら subscription ID と状態(active)を保存。
    $subId = (string) ($s['subscription'] ?? '');
    if (($member['id'] ?? '') !== '' && $subId !== '') {
        db()->prepare("UPDATE members SET stripe_subscription_id = ?, subscription_status = 'active' WHERE id = ?")
            ->execute([$subId, $member['id']]);
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
        $delivered = send_mail($email, '【Enlink】会員サイトのログイン情報', $body) || $delivered;
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
        'subscription'   => (string) $get($session, 'subscription', ''),
        'customer'       => (string) $get($session, 'customer', ''),
        'amount_total'   => (int) $get($session, 'amount_total', 0),
        'currency'       => (string) $get($session, 'currency', 'jpy'),
        'email'          => (string) ($email ?: ''),
        'metadata'       => $meta,
    ];
}

/**
 * Stripe 設定診断。キーの有効性・Priceの継続性・Webhook署名の有無を確認する。
 *
 * @return array{ok:bool, message:string}
 */
function stripe_diagnose(): array
{
    $key = (string) (env('STRIPE_SECRET_KEY') ?? '');
    if ($key === '') {
        return ['ok' => false, 'message' => 'STRIPE_SECRET_KEY が未設定です。'];
    }
    // モード判定（sk=標準 / rk=制限付き）。制限付きキーでも動くよう Account 取得は使わない。
    if (strpos($key, 'sk_live_') === 0 || strpos($key, 'rk_live_') === 0) {
        $mode = '本番(live)';
    } elseif (strpos($key, 'sk_test_') === 0 || strpos($key, 'rk_test_') === 0) {
        $mode = 'テスト(test)';
    } else {
        $mode = '不明';
    }
    init_stripe();

    $priceId = (string) (env('STRIPE_PRICE_ID') ?? '');
    try {
        if ($priceId === '') {
            // Price未設定：キーの有効性だけ Prices:Read で確認。
            \Stripe\Price::all(['limit' => 1]);
            return ['ok' => false, 'message' => "キーは有効です（モード:{$mode}）。ただし STRIPE_PRICE_ID が未設定です。月額の継続Priceを設定してください。"];
        }
        $price = \Stripe\Price::retrieve($priceId);
    } catch (\Stripe\Exception\AuthenticationException $e) {
        return ['ok' => false, 'message' => "Stripeキーが無効です（モード:{$mode}）。キーの値を確認してください。"];
    } catch (\Stripe\Exception\PermissionException $e) {
        return ['ok' => false, 'message' => "キーは有効ですが権限不足（モード:{$mode}）。制限付きキーに Prices の読み取り権限を付与してください。"];
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => "キーOK（モード:{$mode}）。Price『{$priceId}』を取得できません。ID誤りか、test/live の不一致の可能性。"];
    }
    $recurring = isset($price->recurring) && $price->recurring !== null;
    if (!$recurring) {
        return ['ok' => false, 'message' => "Price は継続(recurring)ではありません。月額課金用の継続Priceを指定してください。"];
    }
    $amount = (int) ($price->unit_amount ?? 0);
    $cur = strtoupper((string) ($price->currency ?? ''));
    $interval = (string) ($price->recurring->interval ?? '');
    $active = (bool) ($price->active ?? false);
    $whset = env('STRIPE_WEBHOOK_SECRET') ? 'あり' : 'なし（未設定）';
    $warn = $active ? '' : '　※注意: このPriceは無効(active=false)です。';
    return [
        'ok' => $active,
        'message' => "Stripe設定OK。モード:{$mode} ／ Price: {$amount} {$cur}・毎{$interval} ／ Webhook署名: {$whset}{$warn}",
    ];
}

/* ============================ 料金フェーズ（Ver.1） ============================ */

/** 無料枠の上限（この人数まで無料。既定100）。 */
function billing_free_limit(): int
{
    return max(1, (int) env('BILLING_FREE_LIMIT', '100'));
}

/** アクセスを持つ会員数（status=active）。 */
function active_member_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn();
}

/**
 * 課金フェーズが始まっているか。会員数が無料上限を超えた(=101人目)時点で開始し、
 * 一度始まったら app_settings に記録して戻さない。
 */
function billing_started(): bool
{
    if (app_setting_get('billing_started') === '1') {
        return true;
    }
    if (active_member_count() > billing_free_limit()) {
        app_setting_set('billing_started', '1');
        return true;
    }
    return false;
}

/**
 * この会員が「サブスク登録しないとアクセス制限」対象か。
 * 課金フェーズ中で、サブスクが有効(active)でない会員が対象。
 * ※紹介特典で無料化(100%割引)された会員は subscription_status='active' のため対象外（アクセス可）。
 */
function member_requires_subscription(array $member): bool
{
    if (!billing_started()) {
        return false; // 無料フェーズは全員アクセス可
    }
    return (string) ($member['subscription_status'] ?? '') !== 'active';
}

/* ============================ 無料入会（無料フェーズ：LINE申込→承認発行） ============================ */

/**
 * 無料フェーズの入会：LINE連絡先を承認し、決済なしで会員資格を発行する（冪等）。
 * すでに会員紐付け済みなら再発行しない。発行した資格情報は LINE/メールで配布する。
 *
 * @return array{status:string, member_id:?string, issued:bool}
 *   status: 'done'（新規発行）/ 'linked'（既存会員に紐付け済み）/ 'ignored'（連絡先なし）
 */
function provision_free_member_from_contact(string $userId): array
{
    $c = find_line_contact($userId);
    if ($c === null) {
        return ['status' => 'ignored', 'member_id' => null, 'issued' => false];
    }
    // すでに会員が紐付いていれば発行しない（冪等）。
    if (!empty($c['member_id'])) {
        return ['status' => 'linked', 'member_id' => (string) $c['member_id'], 'issued' => false];
    }
    // メール一致の既存会員があれば紐付けのみ（重複アカウント防止）。
    $email = (string) ($c['email'] ?? '');
    $existing = $email !== '' ? find_member_by_email($email) : null;
    if ($existing !== null) {
        activate_member((string) $existing['id'], null, $userId);
        link_line_contact_member($userId, (string) $existing['id']);
        set_line_contact_state($userId, 'paid');
        return ['status' => 'linked', 'member_id' => (string) $existing['id'], 'issued' => false];
    }

    // 新規会員を無料で発行（active・初回PW強制変更）。
    $cred = issue_member_credentials($email !== '' ? $email : null, null, 'active', $userId);
    $member = find_member_by_id($cred['member_id']);
    link_line_contact_member($userId, (string) $member['id']);
    set_line_contact_state($userId, 'paid');
    audit_log('member.free_provisioned', ['member' => $member['id'], 'line' => substr($userId, 0, 10)]);
    deliver_member_credentials($member, $cred['login_id'], $cred['temp_password']);
    return ['status' => 'done', 'member_id' => (string) $member['id'], 'issued' => true];
}

/**
 * LINE連絡先を「承認」する。料金フェーズに応じて発行方法を切り替える。
 *  - 無料フェーズ：決済なしで会員資格を発行して配布。
 *  - 課金フェーズ：入会金/月額の決済リンクを送る（従来どおり）。
 *
 * @return array{ok:bool, phase:string, message:string, member_id:?string}
 */
function approve_line_contact(string $userId): array
{
    if (find_line_contact($userId) === null) {
        return ['ok' => false, 'phase' => '', 'message' => 'LINE連絡先が見つかりません。', 'member_id' => null];
    }
    set_line_contact_approved($userId, true);

    if (billing_started()) {
        // 課金フェーズ：決済リンクを送信。
        $ok = send_payment_link_to_contact($userId);
        return [
            'ok' => $ok,
            'phase' => 'paid',
            'message' => $ok ? '承認し、決済リンクを送信しました。' : '承認しましたが送信に失敗しました（LINE設定をご確認ください）。',
            'member_id' => null,
        ];
    }

    // 無料フェーズ：決済なしで会員資格を発行。
    $r = provision_free_member_from_contact($userId);
    if ($r['status'] === 'done') {
        return ['ok' => true, 'phase' => 'free', 'message' => '承認し、会員資格（無料）を発行してLINEに送信しました。', 'member_id' => $r['member_id']];
    }
    if ($r['status'] === 'linked') {
        return ['ok' => true, 'phase' => 'free', 'message' => '承認しました。既存の会員に紐付けました。', 'member_id' => $r['member_id']];
    }
    return ['ok' => false, 'phase' => 'free', 'message' => '承認しましたが会員発行に失敗しました。', 'member_id' => null];
}

/* ============================ 紹介特典（月額無料化） ============================ */

/**
 * 無料化に必要な「アクティブな紹介先」の人数（既定5）。
 */
function referral_waiver_min(): int
{
    return max(1, (int) env('REFERRAL_WAIVER_MIN', '5'));
}

/**
 * 紹介判定モード。
 *  - 'A'（既定）：無料化した紹介先(active)もカウントする（拡散重視）。
 *  - 'B'：実際に課金している紹介先(active かつ無料化していない)だけカウントする（収益の下限を保証）。
 * app_settings 'referral_waiver_mode' で切替可能（運営ダッシュボードから）。
 */
function referral_waiver_mode(): string
{
    $m = strtoupper((string) app_setting_get('referral_waiver_mode', 'A'));
    return $m === 'B' ? 'B' : 'A';
}

/**
 * 指定会員が紹介した人のうち「アクティブ」な人数を数える。
 * A案: subscription_status='active' の紹介先すべて。
 * B案: さらに subscription_waived=0（＝実際に課金している）に限定。
 */
function count_active_referrals(string $referrerId, bool $payingOnly): int
{
    $sql = "SELECT COUNT(*)
              FROM referrals r
              JOIN members m ON m.id = r.joiner_id
             WHERE r.referrer_id = ?
               AND m.subscription_status = 'active'";
    if ($payingOnly) {
        $sql .= ' AND COALESCE(m.subscription_waived, 0) = 0';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$referrerId]);
    return (int) $stmt->fetchColumn();
}

/** 現在、紹介特典で無料化されている会員数（モニター用）。 */
function waived_member_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM members WHERE COALESCE(subscription_waived,0) = 1')->fetchColumn();
}

/** サブスク登録済み（active）会員数（無料比率モニターの母数）。 */
function subscribed_member_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM members WHERE subscription_status = 'active'")->fetchColumn();
}

/**
 * 無料化に使う 100%OFF クーポンのIDを返す（無ければ Stripe 上に作成して app_settings に保存）。
 * 明示指定したい場合は .env の REFERRAL_WAIVER_COUPON_ID が最優先。
 */
function waiver_coupon_id(): string
{
    $envId = (string) (env('REFERRAL_WAIVER_COUPON_ID') ?? '');
    if ($envId !== '') {
        return $envId;
    }
    $saved = (string) (app_setting_get('referral_waiver_coupon_id', '') ?? '');
    if ($saved !== '') {
        return $saved;
    }
    // 100%OFF・継続(forever)のクーポンを作成。付いている間ずっと無料、外せば通常額に戻る。
    $coupon = \Stripe\Coupon::create([
        'percent_off' => 100,
        'duration'    => 'forever',
        'name'        => 'Enlink紹介特典(月額無料)',
    ]);
    $id = (string) $coupon->id;
    app_setting_set('referral_waiver_coupon_id', $id);
    return $id;
}

/**
 * 会員のサブスクに 100%OFF クーポンを適用して無料化する（冪等）。
 * すでに無料化済み(subscription_waived=1)なら何もしない。
 *
 * @return bool 新たに適用したら true
 */
function apply_subscription_waiver(array $member): bool
{
    $subId = (string) ($member['stripe_subscription_id'] ?? '');
    if ($subId === '' || (int) ($member['subscription_waived'] ?? 0) === 1) {
        return false;
    }
    \Stripe\Subscription::update($subId, ['coupon' => waiver_coupon_id()]);
    db()->prepare('UPDATE members SET subscription_waived = 1 WHERE id = ?')->execute([$member['id']]);
    audit_log('waiver.applied', ['member' => $member['id']]);
    return true;
}

/**
 * 会員のサブスクからクーポンを外して通常額に戻す（冪等）。
 * すでに通常額(subscription_waived=0)なら何もしない。
 *
 * @return bool 新たに解除したら true
 */
function remove_subscription_waiver(array $member): bool
{
    $subId = (string) ($member['stripe_subscription_id'] ?? '');
    if ($subId === '' || (int) ($member['subscription_waived'] ?? 0) === 0) {
        return false;
    }
    try {
        \Stripe\Subscription::deleteDiscount($subId);
    } catch (\Throwable $e) {
        // すでに割引が無い場合などは無視（フラグだけ戻す）。
    }
    db()->prepare('UPDATE members SET subscription_waived = 0 WHERE id = ?')->execute([$member['id']]);
    audit_log('waiver.removed', ['member' => $member['id']]);
    return true;
}

/**
 * 全 active サブスク会員について、紹介特典（月額無料化）の付け外しを判定・反映する。
 * cron（bin/referral_waiver.php）から定期実行する。Stripe を叩くので冪等ガード付き。
 *
 * @return array{scanned:int, applied:int, removed:int, errors:int, mode:string}
 */
function evaluate_referral_waiver(): array
{
    $mode = referral_waiver_mode();
    $payingOnly = ($mode === 'B');
    $min = referral_waiver_min();
    $scanned = $applied = $removed = $errors = 0;

    // 課金フェーズでのみ意味を持つ（無料フェーズはサブスク自体が無い）。
    $rows = db()->query("SELECT * FROM members WHERE subscription_status = 'active' AND stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> ''")->fetchAll();
    foreach ($rows as $m) {
        $scanned++;
        try {
            $count = count_active_referrals((string) $m['id'], $payingOnly);
            $shouldBeFree = $count >= $min;
            $isFree = (int) ($m['subscription_waived'] ?? 0) === 1;
            if ($shouldBeFree && !$isFree) {
                if (apply_subscription_waiver($m)) {
                    $applied++;
                }
            } elseif (!$shouldBeFree && $isFree) {
                if (remove_subscription_waiver($m)) {
                    $removed++;
                }
            }
        } catch (\Throwable $e) {
            $errors++;
            error_log('waiver eval error member=' . ($m['id'] ?? '-') . ': ' . $e->getMessage());
        }
    }
    return ['scanned' => $scanned, 'applied' => $applied, 'removed' => $removed, 'errors' => $errors, 'mode' => $mode];
}

/* ============================ サブスク（月額会費） ============================ */

/** Stripe 顧客IDから会員を引く。 */
function find_member_by_customer(string $customerId): ?array
{
    if ($customerId === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM members WHERE stripe_customer_id = ? LIMIT 1');
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * invoice.paid（毎月の課金成功）を処理する。
 * - 会員を有効維持（active / subscription_status=active）。
 * - 紹介者へ「継続ボーナス」を付与（請求1件につき1回だけ・冪等）。
 */
function handle_invoice_paid(array $invoice): void
{
    $customerId = (string) ($invoice['customer'] ?? '');
    $invoiceId = (string) ($invoice['id'] ?? '');
    $member = find_member_by_customer($customerId);
    if ($member === null) {
        return; // 会員未特定（初回はcheckout側でプロビジョニング済みになる）
    }
    $memberId = (string) $member['id'];

    // 課金継続 → 会員を有効に戻す/維持。
    db()->prepare("UPDATE members SET status = 'active', subscription_status = 'active', joined_at = COALESCE(joined_at, ?) WHERE id = ?")
        ->execute([time(), $memberId]);

    // 紹介者への継続ボーナス（この会員=入会者を紹介した人へ）。
    award_monthly_referral($memberId, $invoiceId);
}

/**
 * 入会者(=$joinerId)の課金継続に対して、紹介者へ月次ポイントを付与する（冪等）。
 * invoice_id 単位で二重付与を防ぐ。
 */
function award_monthly_referral(string $joinerId, string $invoiceId): void
{
    if ($invoiceId === '') {
        return;
    }
    $stmt = db()->prepare('SELECT referrer_id FROM referrals WHERE joiner_id = ? LIMIT 1');
    $stmt->execute([$joinerId]);
    $referrerId = $stmt->fetchColumn();
    if ($referrerId === false || $referrerId === null || $referrerId === '') {
        return; // 紹介者未登録
    }
    $points = points_amount('referral_monthly');
    if ($points <= 0) {
        return;
    }
    // invoice_id を PK にした claim-first で二重付与を防止。
    $ins = db()->prepare('INSERT OR IGNORE INTO referral_payouts (invoice_id, referrer_id, joiner_id, points, created_at) VALUES (?,?,?,?,?)');
    $ins->execute([$invoiceId, (string) $referrerId, $joinerId, $points, time()]);
    if ($ins->rowCount() === 0) {
        return; // 既に付与済み
    }
    add_points((string) $referrerId, $points, 'referral_monthly', $joinerId);
}

/**
 * サブスクの状態変更を会員へ反映する。
 * - canceled/unpaid → 会員を停止（suspended）。
 * - past_due → subscription_status のみ更新（猶予・停止はしない）。
 */
function handle_subscription_status(string $customerId, string $subStatus, string $subscriptionId = ''): void
{
    $member = find_member_by_customer($customerId);
    if ($member === null) {
        return;
    }
    $memberId = (string) $member['id'];
    if (in_array($subStatus, ['canceled', 'unpaid', 'incomplete_expired'], true)) {
        db()->prepare("UPDATE members SET status = 'suspended', subscription_status = ? WHERE id = ?")
            ->execute([$subStatus, $memberId]);
        audit_log('subscription.suspended', ['member' => $memberId, 'status' => $subStatus]);
    } else {
        db()->prepare('UPDATE members SET subscription_status = ? WHERE id = ?')
            ->execute([$subStatus, $memberId]);
    }
}
