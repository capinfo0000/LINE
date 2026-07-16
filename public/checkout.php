<?php

/**
 * Stripe Checkout セッションを作成し、Stripe の決済ページへリダイレクトする。
 *
 * 【カード情報は扱わない】
 * ここではイベント情報と金額を Stripe に渡してセッションを作るだけ。
 * 実際のカード入力は次の Stripe ホスト画面で行われ、当サーバーには一切渡りません。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST のみ許可されています。');
}

// 濫用対策: 未認証の申込（当日申込はメール送信・Stripe顧客作成を伴う）は
// 同一IPからの回数制限＋CAPTCHA で抑止する。メール爆撃・偽顧客大量投入・定員枠潰しを防ぐ。
if (!rate_limit_check('apply', 20, 3600)) {
    http_response_code(429);
    exit('申込の試行が多すぎます。しばらく時間をおいて再度お試しください。');
}
if (!captcha_verify($_POST['cf-turnstile-response'] ?? null)) {
    http_response_code(400);
    exit('認証（CAPTCHA）に失敗しました。前の画面に戻ってもう一度お試しください。');
}

$eventId = (string)($_POST['event_id'] ?? '');
$event = find_event($eventId);

if ($event === null) {
    http_response_code(404);
    exit('指定されたイベントが見つかりません。');
}

// 決済はイベント所有者の Stripe で行う。所有者の画面登録鍵→Connect→プラットフォームの順で文脈確立。
$account = stripe_resolve_event($event);

// 申込フォームの入力を受け取り・検証する（金額は必ずサーバー側のイベント定義から確定）
$name  = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$note  = trim((string)($_POST['note'] ?? ''));
$partySize = (int)($_POST['party_size'] ?? 1);

if ($name === '' || $email === '') {
    http_response_code(400);
    exit('お名前とメールアドレスは必須です。フォームに戻って入力してください。');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit('メールアドレスの形式が正しくありません。');
}

// 参加人数の上限（capacity があればそれ、無ければ 10）に丸める
$maxParty = (int)($event['capacity'] ?? 0);
if ($maxParty < 1) {
    $maxParty = 10;
}
$maxParty = min($maxParty, 20);
if ($partySize < 1) {
    $partySize = 1;
}
if ($partySize > $maxParty) {
    $partySize = $maxParty;
}

// 支払い方法（事前決済 / 当日支払い）を決定。イベントが許可している方式のみ受け付ける。
$allowPrepay = array_key_exists('allow_prepay', $event) ? !empty($event['allow_prepay']) : true;
$allowOnsite = !empty($event['allow_onsite']);
$paymentType = (string)($_POST['payment_type'] ?? ($allowPrepay ? 'prepay' : 'onsite'));
if ($paymentType !== 'prepay' && $paymentType !== 'onsite') {
    $paymentType = $allowPrepay ? 'prepay' : 'onsite';
}
if ($paymentType === 'prepay' && !$allowPrepay) {
    http_response_code(400);
    exit('このイベントでは事前決済を受け付けていません。');
}
if ($paymentType === 'onsite' && !$allowOnsite) {
    http_response_code(400);
    exit('このイベントでは当日支払いを受け付けていません。');
}

// 決済（事前・当日とも）は運営者の Stripe を使う。鍵未設定なら受付不可。
if (!stripe_ready_for_event($event)) {
    http_response_code(503);
    exit('現在このイベントは決済の準備が完了していません。主催者へお問い合わせください。');
}

// 単価は方式ごとにサーバー側のイベント定義から確定する（改ざん防止）
$currency = $event['currency'] ?? 'jpy';
$prepayUnit = (int)($event['amount'] ?? 0);
$onsiteUnit = (isset($event['amount_onsite']) && $event['amount_onsite'] !== '')
    ? (int)$event['amount_onsite']
    : $prepayUnit;

// 長すぎる入力は Stripe metadata 制限（値は最大500文字）に合わせて切り詰める
$metaName = mb_substr($name, 0, 100);
$metaPhone = mb_substr($phone, 0, 30);
$metaNote = mb_substr($note, 0, 450);

// 運営者自身の Stripe アカウントで初期化（Connect 不使用）。
init_stripe();
$opts = stripe_opts($account); // $account = null → 自アカウント
$capacity = (int)($event['capacity'] ?? 0);
// 定員チェック（capacity>0 のとき）。現在の人数＋今回の人数が定員を超えたら受付不可。
if ($capacity > 0) {
    try {
        $current = event_headcount($event['id'], $account);
    } catch (\Throwable $e) {
        error_log('定員チェックの人数取得失敗: ' . $e->getMessage());
        http_response_code(502);
        exit('申込状況を確認できませんでした。時間をおいて再度お試しください。');
    }
    if ($current + $partySize > $capacity) {
        http_response_code(409);
        $remain = max(0, $capacity - $current);
        exit('申し訳ありません、定員に達しています（残り ' . $remain . ' 名）。');
    }
}

// ---- 当日支払い: 決済は発生させず、課金なしの Stripe 顧客として申込を記録 ----
if ($paymentType === 'onsite') {
    $onsiteTotal = $onsiteUnit * $partySize;
    // 課金なしの Stripe 顧客として、運営者のアカウントに名簿を記録する。
    try {
        \Stripe\Customer::create([
            'name' => $metaName,
            'email' => $email,
            'phone' => $metaPhone,
            'metadata' => [
                'event_id' => $event['id'],
                'event_name' => $event['name'] ?? '',
                'participant_name' => $metaName,
                'phone' => $metaPhone,
                'party_size' => (string)$partySize,
                'note' => $metaNote,
                'payment_type' => 'onsite',
                'onsite_unit' => (string)$onsiteUnit,
                'onsite_total' => (string)$onsiteTotal,
                'currency' => $currency,
            ],
        ], $opts);
    } catch (\Throwable $e) {
        http_response_code(502);
        error_log('当日申込の記録失敗: ' . $e->getMessage());
        exit('申込の受付に失敗しました。時間をおいて再度お試しください。');
    }

    // 申込受付の確認メール（当日支払いは Stripe の領収書が出ないため、こちらで送る）
    $mailBody = ($metaName !== '' ? $metaName . ' 様' : 'ご参加者様') . "\n\n"
        . "下記のお申し込みを受け付けました（当日支払い）。\n\n"
        . 'イベント：' . ($event['name'] ?? '') . "\n"
        . '日時・場所：' . trim(($event['date'] ?? '') . '　' . ($event['place'] ?? '')) . "\n"
        . '参加人数：' . $partySize . " 名\n"
        . '当日お支払い額：' . format_amount($onsiteTotal, $currency) . "\n\n"
        . "当日、会場で上記金額をお支払いください。今回はまだお支払いは発生していません。\n";
    send_mail($email, '【申込受付】' . ($event['name'] ?? 'イベント') . '（当日支払い）', $mailBody);

    $q = http_build_query([
        'event_id' => $event['id'],
        'party_size' => $partySize,
        'total' => $onsiteTotal,
    ]);
    header('Location: ' . base_url() . '/onsite.php?' . $q, true, 303);
    exit;
}

// ---- 事前決済: Stripe Checkout で前払い ----
try {
    $session = \Stripe\Checkout\Session::create([
        'mode' => 'payment',
        'line_items' => [[
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $prepayUnit,
                'product_data' => [
                    'name' => $event['name'] ?? 'イベント参加費',
                    'description' => trim(($event['date'] ?? '') . ' / ' . ($event['place'] ?? '')),
                ],
            ],
            'quantity' => $partySize, // 参加人数ぶんをまとめて決済
        ]],
        // 申込フォームで集めた連絡先を Stripe に引き継ぐ（領収書送付先＝入力メール）
        'customer_creation' => 'always',
        'customer_email' => $email,
        // 集めた情報は Stripe の決済データに metadata として保管（当サーバーのDBは持たない）
        'metadata' => [
            'event_id' => $event['id'],
            'event_name' => $event['name'] ?? '',
            'participant_name' => $metaName,
            'phone' => $metaPhone,
            'party_size' => (string)$partySize,
            'note' => $metaNote,
            'payment_type' => 'prepay',
        ],
        'payment_intent_data' => [
            'metadata' => [
                'event_id' => $event['id'],
                'event_name' => $event['name'] ?? '',
                'participant_name' => $metaName,
                'phone' => $metaPhone,
                'party_size' => (string)$partySize,
            ],
        ],
        // キャンセルポリシーを決済画面のボタン直上に明示（前払い＝後から取り立て不要にする要）
        'custom_text' => [
            'submit' => [
                'message' => 'お支払い後のキャンセルは、キャンセルポリシーに沿った返金規定が適用されます。',
            ],
        ],
        'success_url' => base_url() . '/success.php?event_id=' . urlencode($event['id']) . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => base_url() . '/cancel.php?event_id=' . urlencode($event['id']),
    ], $opts);
} catch (\Throwable $e) {
    // 認証エラー・通信エラー・予期しない応答など、あらゆる決済作成失敗をここで受ける
    http_response_code(502);
    error_log('Stripe Checkout 作成失敗: ' . $e->getMessage());
    exit('決済ページの作成に失敗しました。時間をおいて再度お試しください。');
}

// Stripe のホスト決済ページへ
header('Location: ' . $session->url, true, 303);
exit;
