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
        // 別のサブスクに変わったら（解約→再登録）、無料化フラグを一度倒す。
        // このフラグは「いま Stripe 側にクーポンが付いているか」を表しており、
        // 新しいサブスクには当然まだ付いていない。1 のまま残すと
        // apply_subscription_waiver() の冪等ガードに弾かれて永久にクーポンが付かず、
        // 「無料と表示されているのに毎月請求される」状態になる。
        $prevSubId = (string) ($member['stripe_subscription_id'] ?? '');
        if ($prevSubId !== '' && $prevSubId !== $subId) {
            db()->prepare('UPDATE members SET subscription_waived = 0 WHERE id = ?')->execute([$member['id']]);
        }
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
    $loginUrl = base_url() . '/member/login';
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

/** アクセスを持つ会員数（status=active）。運用の実数を見るとき用。 */
function active_member_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn();
}

/**
 * 「先着◯名」として数える会員数。
 *
 * アカウントを発行しただけの人は数えない。自己紹介まで入った人＝
 * 実際に参加している人だけを数える。IDだけ配られて何も書いていない人を
 * 含めると、無料枠が実態より早く埋まってしまうため。
 *
 * 「自己紹介が入った」は次のどちらかを満たすこととする。
 *  ・公式LINEのトークに自己紹介を送信した（intro_submitted_at）
 *  ・プロフィールの「自己紹介」欄を書いた（profiles.bio）
 * LINE未連携で運営が発行した会員は前者を満たせないので、後者も見る。
 */
function counted_member_count(): int
{
    return (int) db()->query(
        "SELECT COUNT(*) FROM members m
           LEFT JOIN profiles p ON p.member_id = m.id
          WHERE m.status = 'active'
            AND ( (m.intro_submitted_at IS NOT NULL AND m.intro_submitted_at > 0)
               OR (p.bio IS NOT NULL AND TRIM(p.bio) <> '') )"
    )->fetchColumn();
}

/**
 * 無料枠に達した日時（＝上限を超えた最初の瞬間）。まだなら null。
 *
 * 誰かがアクセスした時点で確定させたいので、読み取り時に記録する（cronに依存しない）。
 * 一度記録したら動かさない。
 */
function billing_reached_at(): ?int
{
    $saved = app_setting_get('billing_reached_at');
    if ($saved !== null && $saved !== '' && (int) $saved > 0) {
        return (int) $saved;
    }
    // 旧仕様で billing_started だけが立っている場合は、その時点で到達済みとみなす。
    if (app_setting_get('billing_started') === '1') {
        $now = time();
        app_setting_set('billing_reached_at', (string) $now);
        return $now;
    }
    if (counted_member_count() > billing_free_limit()) {
        $now = time();
        app_setting_set('billing_reached_at', (string) $now);
        return $now;
    }
    return null;
}

/**
 * 課金が始まる日時（到達した月の翌月1日 0:00 JST）。まだ到達していなければ null。
 * 到達したその場で全員を締め出さないための猶予期間。
 */
function billing_starts_at(): ?int
{
    $reached = billing_reached_at();
    if ($reached === null) {
        return null;
    }
    // 翌月1日の0時。12月なら翌年1月1日になる（mktime が繰り上げてくれる）。
    return mktime(0, 0, 0, (int) date('n', $reached) + 1, 1, (int) date('Y', $reached));
}

/** 課金フェーズが始まっているか（猶予期間が明けているか）。 */
function billing_started(): bool
{
    $startsAt = billing_starts_at();
    return $startsAt !== null && time() >= $startsAt;
}

/** 到達済みだが、まだ課金が始まっていない（猶予期間中）か。 */
function billing_grace_active(): bool
{
    return billing_reached_at() !== null && !billing_started();
}

/**
 * 紹介制度（紹介コードの発行・入力）を受け付けているか。
 *
 * 猶予期間から開ける。猶予期間は「有料にするかどうかを会員が選ぶ時期」であり、
 * ここで紹介を積めないと、課金開始の時点で全員の紹介数が0のままになり
 * 「5人紹介したら無料」が誰にも成立しない。
 */
function referral_program_open(): bool
{
    return billing_reached_at() !== null;
}

/**
 * 先着枠の進捗（さがす上部の進捗バー用）。
 *
 * @return array{count:int, limit:int, remaining:int, percent:int}
 */
function billing_progress(): array
{
    $limit = billing_free_limit();
    $count = counted_member_count();
    return [
        'count'     => $count,
        'limit'     => $limit,
        'remaining' => max(0, $limit - $count),
        'percent'   => $limit > 0 ? (int) min(100, round($count / $limit * 100)) : 100,
    ];
}

/** 猶予期間中の案内文。猶予期間でなければ空文字。 */
function billing_grace_notice(): string
{
    if (!billing_grace_active()) {
        return '';
    }
    $startsAt = billing_starts_at();
    return date('n月j日', (int) $startsAt) . 'から、会員機能のご利用に月額会費（' . monthly_fee_text() . '）が必要になります。';
}

/**
 * この会員が「サブスク登録しないとアクセス制限」対象か。
 * 課金フェーズ中で、サブスクが有効(active)でない会員が対象。
 * ※紹介特典で無料化(100%割引)された会員は subscription_status='active' のため対象外（アクセス可）。
 */
function member_requires_subscription(array $member): bool
{
    if (!billing_started()) {
        return false; // 無料フェーズと猶予期間は全員アクセス可
    }
    return (string) ($member['subscription_status'] ?? '') !== 'active';
}

/**
 * この会員が、いま自分から月額会費に登録できるか。
 *
 * member_requires_subscription()（＝「さがす」をロックするか）とは別物なので関数を分ける。
 * 猶予期間は「有料にするかどうかを会員が選ぶ時期」なので、ロックはしないが登録はできる。
 * 100名に到達する前（完全な無料フェーズ）は、まだ制度が始まっていないので登録させない。
 */
function member_can_subscribe_now(array $member): bool
{
    if (billing_reached_at() === null) {
        return false; // まだ無料フェーズ。課金制度そのものが始まっていない
    }
    if ((string) ($member['status'] ?? '') !== 'active') {
        return false; // 停止中の会員は登録させない
    }
    return (string) ($member['subscription_status'] ?? '') !== 'active'; // 登録済みなら不要
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
    // 既定はA案。「紹介した5人が課金したら無料」に加えて、
    // 無料になった人がさらに5人紹介すれば、その人も無料になる（連鎖する）運用のため。
    // 無料が増えすぎたら運営ダッシュボードから B に切り替えられる。
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

/** 「永久無料」の資格を得た会員数（モニター用）。 */
function waiver_permanent_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM members WHERE waiver_earned_at IS NOT NULL AND waiver_earned_at > 0')->fetchColumn();
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
 * クーポンを外しても当月分は請求済み（0円）のままなので、
 * 通常額になるのは次回の請求から＝「来月から」になる。
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
 * 紹介特典の判定に使う人数（第1段：通常の無料化）。
 *
 * A案（既定）で数えるのは「紹介した相手のうち、月額会費を契約している人」。
 * 紹介したあとにその人が自分も無料化された場合も、契約は続いているので数え続ける。
 * 抜けるのは完全に解約した人だけ。
 * B案に切り替えると「実際に会費を払っている人」だけに絞られる。
 */
function referral_waiver_count(string $memberId): int
{
    return count_active_referrals($memberId, referral_waiver_mode() === 'B');
}

/**
 * 永久無料の判定に使う人数（第2段）。
 *
 * 「紹介した相手のうち、その人自身も min 人を紹介している人」の数を返す。
 * つまり自分の下に min×min 名（既定なら 5×5＝25名）が育っている状態を数える。
 *
 * 第1段と同じ数え方（A案／B案）を内側にも適用する。
 */
function count_qualified_referrals(string $referrerId, bool $payingOnly, int $min): int
{
    $waivedCond = $payingOnly ? ' AND COALESCE(%s.subscription_waived, 0) = 0' : '';
    $sql = "SELECT COUNT(*)
              FROM referrals r
              JOIN members m ON m.id = r.joiner_id
             WHERE r.referrer_id = ?
               AND m.subscription_status = 'active'" . sprintf($waivedCond, 'm') . "
               AND (
                    SELECT COUNT(*)
                      FROM referrals r2
                      JOIN members m2 ON m2.id = r2.joiner_id
                     WHERE r2.referrer_id = m.id
                       AND m2.subscription_status = 'active'" . sprintf($waivedCond, 'm2') . "
                   ) >= ?";
    // ※ min は必ず整数として渡すこと。PDO の既定では文字列としてバインドされ、
    //    SQLite は「数値 < 文字列」で型どうしを比較するため COUNT(*) >= '5' が常に偽になる。
    $stmt = db()->prepare($sql);
    $stmt->bindValue(1, $referrerId, \PDO::PARAM_STR);
    $stmt->bindValue(2, $min, \PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/**
 * 永久無料の資格を既に得ているか。
 *
 * 第2段の条件（紹介した5人が、それぞれ5人ずつ紹介した）を一度満たすと、
 * あとで下の人数が減っても無料のままになる。
 */
function member_waiver_earned(array $member): bool
{
    return (int) ($member['waiver_earned_at'] ?? 0) > 0;
}

/**
 * 永久無料の資格を記録する（初回だけ true）。
 *
 * 「まだ資格が無い行だけを更新する」条件付き UPDATE の rowCount で判定するので、
 * 同時に2回走っても二重にはならない（record_stripe_event_once() と同じ claim-first の流儀）。
 */
function claim_waiver_earned(string $memberId): bool
{
    $stmt = db()->prepare(
        'UPDATE members SET waiver_earned_at = ?
          WHERE id = ? AND (waiver_earned_at IS NULL OR waiver_earned_at = 0)'
    );
    $stmt->execute([time(), $memberId]);
    if ($stmt->rowCount() === 0) {
        return false; // 既に資格あり
    }
    audit_log('waiver.earned', ['member' => $memberId]);
    return true;
}

/** いま第2段（永久無料）の条件を満たしているか。 */
function member_qualifies_for_permanent_waiver(array $member): bool
{
    $min = referral_waiver_min();
    return count_qualified_referrals((string) $member['id'], referral_waiver_mode() === 'B', $min) >= $min;
}

/**
 * いま月額を無料にできるか。
 *
 * 永久無料の資格を得ているか、そのときどきの人数が条件に達していれば無料。
 * 資格が無い場合は、紹介先が解約して人数を割れば翌月から通常額に戻る。
 * ※ A案では「紹介したあとに自分も無料になった紹介先」は active のままなので、
 *    人数に残り続ける。抜けるのは完全に解約した紹介先だけ。
 */
function member_qualifies_for_waiver(array $member): bool
{
    if (member_waiver_earned($member)) {
        return true;
    }
    return referral_waiver_count((string) $member['id']) >= referral_waiver_min();
}

/**
 * 紹介特典（月額無料化）の付け外しを判定・反映する。cron から定期実行する。
 *
 * 2段階の運用。
 *  第1段（通常の無料）：紹介先のうち契約中の人が min 人以上いれば無料にする。
 *      割り込んだらクーポンを外す。当月分は請求済み(0円)なので、
 *      通常額になるのは次回の請求から＝「来月から」になる。
 *  第2段（永久無料）：その紹介先が「それぞれ min 人ずつ」紹介していれば、
 *      waiver_earned_at に資格を記録する。以後は人数が減っても外さない。
 *
 * サブスクを持たない会員は対象にしない（Stripe に問い合わせても意味がないため）。
 * 猶予期間中に条件を達成した未登録の会員は、申込時に subscribe.php が
 * member_qualifies_for_waiver() を見てクーポンを付けるので取りこぼさない。
 *
 * Stripe を叩くので冪等ガード付き。
 *
 * @return array{scanned:int, earned:int, applied:int, removed:int, errors:int, mode:string}
 */
function evaluate_referral_waiver(): array
{
    $mode = referral_waiver_mode();
    $payingOnly = ($mode === 'B');
    $min = referral_waiver_min();
    $scanned = $earned = $applied = $removed = $errors = 0;

    $rows = db()->query(
        "SELECT * FROM members
          WHERE subscription_status = 'active'
            AND stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> ''"
    )->fetchAll();
    foreach ($rows as $m) {
        $scanned++;
        try {
            $isPermanent = member_waiver_earned($m);
            // 第2段：永久無料の条件を満たしたら資格を記録する（一度きり）。
            if (!$isPermanent && count_qualified_referrals((string) $m['id'], $payingOnly, $min) >= $min) {
                if (claim_waiver_earned((string) $m['id'])) {
                    $earned++;
                    $isPermanent = true;
                }
            }
            // 第1段：いまの人数で無料かどうか。永久資格があれば人数に関わらず無料。
            $shouldBeFree = $isPermanent || count_active_referrals((string) $m['id'], $payingOnly) >= $min;
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
    return ['scanned' => $scanned, 'earned' => $earned, 'applied' => $applied, 'removed' => $removed, 'errors' => $errors, 'mode' => $mode];
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
 * - trialing → 'active' として保存する（下記）。
 */
function handle_subscription_status(string $customerId, string $subStatus, string $subscriptionId = ''): void
{
    $member = find_member_by_customer($customerId);
    if ($member === null) {
        return;
    }
    // 猶予期間中の申込には trial_end（初回請求を課金開始日に合わせる）を付けるため、
    // Stripe は 'trialing' を返してくる。だがアプリ側は「有効な契約か」を
    // subscription_status === 'active' で判定している箇所が多く（ロック判定・紹介の
    // カウント・プラン・一覧の並び）、trialing を持ち込むと全部が「未契約」に倒れる。
    // 実態は「契約済みで、初回請求がまだ先」なので active として保存する。
    if ($subStatus === 'trialing') {
        $subStatus = 'active';
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
