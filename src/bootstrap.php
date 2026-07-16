<?php

/**
 * アプリ共通の初期化処理。
 *
 * 【重要・設計思想】
 * このアプリのサーバー（PHP）は、クレジットカード情報を一切受け取らず・保存しません。
 * カード番号・有効期限・セキュリティコードの入力は、すべて Stripe がホストする
 * 決済ページ（Stripe Checkout）上で行われます。PCI DSS 準拠は Stripe 側の責任範囲です。
 * このサーバーが扱うのは「どのイベントに、誰（氏名・メール）が申し込んだか」だけです。
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';

// データ層・テナント（マルチテナント）ヘルパー。関数定義のみで、呼び出し時に env() を使う。
require __DIR__ . '/db.php';
require __DIR__ . '/tenant.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/captcha.php';
require __DIR__ . '/crypto.php';

/**
 * .env を読み込んで getenv() / $_ENV から参照できるようにする簡易ローダー。
 * （依存を増やさないため自前実装。値はクオート除去のみの素朴なパース。）
 */
function load_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // 前後のクオートを外す
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

load_env(APP_ROOT . '/.env');

/**
 * このリクエスト用の CSP nonce（1リクエストにつき1つ）。
 * インライン <script>/<style> に nonce 属性として付け、'unsafe-inline' なしで許可する。
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/**
 * リクエストが HTTPS で配信されているか（リバースプロキシ経由も考慮）。
 * APP_BASE_URL が https の場合も「HTTPS 配信前提」とみなす。
 */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    return str_starts_with(strtolower((string) getenv('APP_BASE_URL')), 'https://');
}

/**
 * 全レスポンス共通のセキュリティヘッダを送る（出力前に bootstrap で1回だけ）。
 * - クリックジャッキング対策（frame-ancestors / X-Frame-Options）
 * - MIME スニッフィング抑止、リファラ最小化
 * - HTTPS 配信時は HSTS
 * インラインの style/script/イベントハンドラを使う既存UIを壊さないため、
 * script/style は 'unsafe-inline' を許可しつつ、frame/base/form を厳格化する。
 */
function send_baseline_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    // CAPTCHA(Turnstile)有効時は、そのウィジェット配信元を許可リストに加える。
    $captchaHost = captcha_enabled() ? ' https://challenges.cloudflare.com' : '';
    $nonce = "'nonce-" . csp_nonce() . "'";
    // script はインラインを禁止し、自ホスト＋nonce のみ許可（XSS耐性）。
    // style は <style nonce> と style属性の両方を許可するため style-src-attr 'unsafe-inline' を併用。
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self' $nonce; style-src-attr 'unsafe-inline'; "
        . "script-src 'self' $nonce" . $captchaHost . "; "
        . "connect-src 'self'" . $captchaHost . "; "
        . "frame-src" . ($captchaHost !== '' ? $captchaHost : " 'none'") . "; "
        . "object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

send_baseline_security_headers();

/**
 * 環境変数を取得。必須かつ未設定なら例外。
 */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function env_required(string $key): string
{
    $value = env($key);
    if ($value === null) {
        http_response_code(500);
        exit("設定エラー: 環境変数 {$key} が未設定です。.env を確認してください。\n");
    }
    return $value;
}

/**
 * DB の行を、画面・決済処理が期待する形に正規化する（型変換つき）。
 * 'stripe_account_id' は所有テナントの接続アカウント（公開申込で利用）。
 */
function event_normalize(array $row): array
{
    return [
        'id'                => (string) $row['id'],
        'tenant_id'         => (string) $row['tenant_id'],
        'name'              => (string) $row['name'],
        'description'       => (string) ($row['description'] ?? ''),
        'date'              => (string) ($row['date'] ?? ''),
        'place'             => (string) ($row['place'] ?? ''),
        'amount'            => (int) ($row['amount'] ?? 0),
        'amount_onsite'     => (int) ($row['amount_onsite'] ?? 0),
        'currency'          => (string) ($row['currency'] ?? 'jpy'),
        'capacity'          => (int) ($row['capacity'] ?? 0),
        'allow_prepay'      => (int) ($row['allow_prepay'] ?? 1) === 1,
        'allow_onsite'      => (int) ($row['allow_onsite'] ?? 0) === 1,
        'stripe_account_id' => $row['stripe_account_id'] ?? null,
        'created_at'        => (int) ($row['created_at'] ?? 0),
    ];
}

/**
 * イベントを ID で取得（所有テナントの Stripe 接続アカウントも併せて取得）。
 * 公開申込ページなど、ログイン不要の文脈からも使う。
 */
function find_event(string $id): ?array
{
    $stmt = db()->prepare(
        'SELECT e.*, t.stripe_account_id
           FROM events e JOIN tenants t ON t.id = e.tenant_id
          WHERE e.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? event_normalize($row) : null;
}

/**
 * 指定テナントのイベント一覧（新しい順）。
 */
function tenant_events(string $tenantId): array
{
    $stmt = db()->prepare(
        'SELECT e.*, t.stripe_account_id
           FROM events e JOIN tenants t ON t.id = e.tenant_id
          WHERE e.tenant_id = ? ORDER BY e.created_at DESC'
    );
    $stmt->execute([$tenantId]);
    return array_map('event_normalize', $stmt->fetchAll());
}

/** 重複しないイベントIDを生成する。 */
function generate_event_id(): string
{
    do {
        $id = 'ev_' . bin2hex(random_bytes(6));
    } while (find_event($id) !== null);
    return $id;
}

/**
 * イベントを作成して ID を返す（所有テナントを指定）。
 * @param array<string,mixed> $d 正規化済みの値（amount 等は整数、allow_* は bool）
 */
function create_event(string $tenantId, array $d): string
{
    $id = generate_event_id();
    $stmt = db()->prepare(
        'INSERT INTO events (id, tenant_id, name, description, date, place, amount, amount_onsite, currency, capacity, allow_prepay, allow_onsite, created_at)
         VALUES (:id,:tenant,:name,:desc,:date,:place,:amount,:onsite,:cur,:cap,:ap,:ao,:ts)'
    );
    $stmt->execute([
        ':id' => $id, ':tenant' => $tenantId,
        ':name' => $d['name'], ':desc' => $d['description'], ':date' => $d['date'], ':place' => $d['place'],
        ':amount' => $d['amount'], ':onsite' => $d['amount_onsite'], ':cur' => $d['currency'], ':cap' => $d['capacity'],
        ':ap' => $d['allow_prepay'] ? 1 : 0, ':ao' => $d['allow_onsite'] ? 1 : 0, ':ts' => time(),
    ]);
    return $id;
}

/**
 * イベントを更新（所有テナントに限定）。更新できたら true。
 */
function update_event(string $tenantId, string $id, array $d): bool
{
    $stmt = db()->prepare(
        'UPDATE events SET name=:name, description=:desc, date=:date, place=:place,
                amount=:amount, amount_onsite=:onsite, currency=:cur, capacity=:cap,
                allow_prepay=:ap, allow_onsite=:ao
          WHERE id=:id AND tenant_id=:tenant'
    );
    $stmt->execute([
        ':name' => $d['name'], ':desc' => $d['description'], ':date' => $d['date'], ':place' => $d['place'],
        ':amount' => $d['amount'], ':onsite' => $d['amount_onsite'], ':cur' => $d['currency'], ':cap' => $d['capacity'],
        ':ap' => $d['allow_prepay'] ? 1 : 0, ':ao' => $d['allow_onsite'] ? 1 : 0,
        ':id' => $id, ':tenant' => $tenantId,
    ]);
    return $stmt->rowCount() > 0;
}

/** イベントを削除（所有テナントに限定）。 */
function delete_event(string $tenantId, string $id): bool
{
    $stmt = db()->prepare('DELETE FROM events WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$id, $tenantId]);
    $ok = $stmt->rowCount() > 0;
    if ($ok) {
        // 残席キャッシュの残骸を掃除
        $del = db()->prepare('DELETE FROM headcount_cache WHERE event_id = ?');
        $del->execute([$id]);
    }
    return $ok;
}

/**
 * 料金プランの定義。max_events は「同じ開催月に登録できるイベント数」の上限。
 * price は月額（最小通貨単位・JPY）。実際の課金連携は別途。
 *
 * @return array<string, array{label:string, max_events:int, price:int}>
 */
function plan_catalog(): array
{
    return [
        'free'      => ['label' => '無料',         'max_events' => 1,           'price' => 0],
        'p5'        => ['label' => '月5イベント',   'max_events' => 5,           'price' => 500],
        'p10'       => ['label' => '月10イベント',  'max_events' => 10,          'price' => 1000],
        'unlimited' => ['label' => '無制限',        'max_events' => PHP_INT_MAX, 'price' => 1500],
    ];
}

/** プランが同じ開催月に登録できるイベント数。未知のプランは無料相当(1)。 */
function plan_max_events(string $plan): int
{
    return plan_catalog()[$plan]['max_events'] ?? 1;
}

/** プランの表示名。 */
function plan_label(string $plan): string
{
    return plan_catalog()[$plan]['label'] ?? $plan;
}

/**
 * 各有料プランに対応する Stripe Price ID（.env で設定）。
 * 未設定のプランは課金導線に出さない。料金は Stripe 側の Price が正。
 *
 * @return array<string,string> plan => price_id
 */
function plan_price_ids(): array
{
    $map = [
        'p5'        => env('STRIPE_PRICE_P5'),
        'p10'       => env('STRIPE_PRICE_P10'),
        'unlimited' => env('STRIPE_PRICE_UNLIMITED'),
    ];
    return array_filter($map, static fn ($v) => $v !== null && $v !== '');
}

/** Stripe Price ID から内部プラン名を引く（Webhook で使用）。無ければ null。 */
function plan_for_price_id(string $priceId): ?string
{
    foreach (plan_price_ids() as $plan => $pid) {
        if ($pid === $priceId) {
            return $plan;
        }
    }
    return null;
}

/** テナントの登録済みイベント総数（表示用）。 */
function tenant_event_count(string $tenantId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM events WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    return (int) $stmt->fetchColumn();
}

/**
 * イベントの「開催月」を 'YYYY-MM' 形式で返す。日付文字列から年月を抽出。
 * 判定できなければ null（プランの月内上限はイベント開催月で数えるため必要）。
 */
function event_month(string $dateStr): ?string
{
    if (preg_match('/(\d{4})\D{1,3}(\d{1,2})/', $dateStr, $m)) {
        $year = (int) $m[1];
        $month = (int) $m[2];
        if ($month >= 1 && $month <= 12) {
            return sprintf('%04d-%02d', $year, $month);
        }
    }
    return null;
}

/**
 * 指定テナントが、ある開催月に登録済みのイベント数を数える（プラン上限の判定用）。
 * $excludeId を渡すと、そのイベント自身は除外する（編集時の自己重複回避）。
 */
function tenant_month_event_count(string $tenantId, string $month, string $excludeId = ''): int
{
    $n = 0;
    foreach (tenant_events($tenantId) as $e) {
        if ($e['id'] === $excludeId) {
            continue;
        }
        if (event_month($e['date']) === $month) {
            $n++;
        }
    }
    return $n;
}

/**
 * イベントの現在の参加人数（party_size 合計）。
 * 事前決済（返金済みを除く）＋当日支払いを数える。定員判定に使う。
 */
function event_headcount(string $eventId, ?string $account): int
{
    $n = 0;
    foreach (fetch_event_participants($eventId, $account) as $p) {
        if (!empty($p['fully_refunded'])) {
            continue; // 全額返金＝キャンセル扱いは定員に数えない
        }
        $n += max(1, (int) $p['party_size']);
    }
    return $n;
}

/**
 * 残席算定（event_headcount）の短時間キャッシュ付きラッパー。
 * 公開ページ（apply/o）の表示用に使い、Stripe 全件取得の連打を防ぐ。
 * 定員の最終判定（checkout.php）はキャッシュを使わず event_headcount() を直接呼ぶこと。
 */
function event_headcount_cached(string $eventId, ?string $account, int $ttl = 60): int
{
    $stmt = db()->prepare('SELECT headcount, updated_at FROM headcount_cache WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    if ($row !== false && (time() - (int) $row['updated_at']) < $ttl) {
        return (int) $row['headcount'];
    }
    $n = event_headcount($eventId, $account);
    $up = db()->prepare('INSERT OR REPLACE INTO headcount_cache (event_id, headcount, updated_at) VALUES (?, ?, ?)');
    $up->execute([$eventId, $n, time()]);
    return $n;
}

/**
 * このアプリの公開ベースURL（success/cancel/webhook の組み立てに使用）。
 * ローカル開発では APP_BASE_URL=http://localhost:8000 を想定。
 */
function base_url(): string
{
    return rtrim(env('APP_BASE_URL', 'http://localhost:8000'), '/');
}

/**
 * このリクエストで使う Stripe APIキーの上書き（主催者が画面登録した鍵）。
 * セットされていれば init_stripe() はプラットフォーム鍵ではなくこの鍵を使う。
 */
function stripe_active_key(?string $set = null, bool $clear = false): ?string
{
    static $key = null;
    if ($clear) {
        $key = null;
    } elseif ($set !== null) {
        $key = $set;
    }
    return $key;
}

/**
 * Stripe SDK を初期化。優先順位:
 *   1) 主催者が画面登録した鍵（stripe_active_key）
 *   2) プラットフォームの STRIPE_SECRET_KEY
 * Connect 利用時は各 API 呼び出しで stripe_opts($accountId) によりアカウント指定。
 */
function init_stripe(): void
{
    $key = stripe_active_key() ?? env('STRIPE_SECRET_KEY');
    if ($key === null) {
        // 例外にして呼び出し側の try/catch で受け、500 即死や白画面を避ける。
        throw new \RuntimeException('Stripe の鍵が設定されていません。');
    }
    \Stripe\Stripe::setApiKey($key);
    $clientId = env('STRIPE_CONNECT_CLIENT_ID');
    if ($clientId !== null) {
        \Stripe\Stripe::setClientId($clientId);
    }
}

/**
 * 管理コンテキストで、ログイン中テナントの Stripe 文脈を確立する。
 * - 画面登録鍵があれば active key にセットし、接続アカウントは使わない（null を返す）。
 * - 無ければ Connect 接続アカウント（あれば）にフォールバック。
 * 戻り値は既存の $account（acct_... または null）。init_stripe() が active key を拾う。
 */
function stripe_resolve_tenant(array $tenant): ?string
{
    stripe_active_key(null, true); // リセット
    $key = get_tenant_stripe_key($tenant);
    if ($key !== null) {
        stripe_active_key($key);
        return null;
    }
    return effective_stripe_account($tenant['stripe_account_id'] ?? null);
}

/**
 * 公開コンテキストで、イベント所有者の Stripe 文脈を確立する。
 * 所有者の画面登録鍵があれば active key に、無ければ Connect/プラットフォームへフォールバック。
 */
function stripe_resolve_event(array $event): ?string
{
    stripe_active_key(null, true);
    $ownerId = (string) ($event['tenant_id'] ?? '');
    if ($ownerId !== '') {
        $owner = find_tenant_by_id($ownerId);
        if ($owner !== null) {
            $key = get_tenant_stripe_key($owner);
            if ($key !== null) {
                stripe_active_key($key);
                return null;
            }
        }
    }
    return effective_stripe_account($event['stripe_account_id'] ?? null);
}

/**
 * テナントが決済可能な Stripe 文脈を持つか。
 * 「実際に使える鍵」で判定する（ファイル存在だけでは不十分＝復号できないと init で失敗するため）。
 */
function stripe_ready_for_tenant(array $tenant): bool
{
    return get_tenant_stripe_key($tenant) !== null || env('STRIPE_SECRET_KEY') !== null;
}

/** イベント所有者が決済可能な Stripe 文脈を持つか（実際に使える鍵で判定）。 */
function stripe_ready_for_event(array $event): bool
{
    $ownerId = (string) ($event['tenant_id'] ?? '');
    if ($ownerId !== '') {
        $owner = find_tenant_by_id($ownerId);
        if ($owner !== null && get_tenant_stripe_key($owner) !== null) {
            return true;
        }
    }
    return env('STRIPE_SECRET_KEY') !== null;
}

/**
 * Stripe Connect（主催者ごとに自分の Stripe を接続して物理分離）が利用可能な構成か。
 * プラットフォームの秘密鍵と Connect の client_id（ca_...）の両方が設定済みなら true。
 */
function connect_enabled(): bool
{
    return env('STRIPE_SECRET_KEY') !== null && env('STRIPE_CONNECT_CLIENT_ID') !== null;
}

/**
 * 操作に使う接続アカウントID を決める。
 * 接続済みなら接続アカウント（acct_...）、未接続なら null（＝プラットフォーム自アカウント＝後方互換）。
 */
function effective_stripe_account(?string $connectedAccountId): ?string
{
    return ($connectedAccountId !== null && $connectedAccountId !== '') ? $connectedAccountId : null;
}

/**
 * 金額を「¥3,000」形式に整形（JPYは最小単位＝円なのでそのまま）。
 */
function format_amount(int $amount, string $currency): string
{
    if (strtolower($currency) === 'jpy') {
        return '¥' . number_format($amount);
    }
    return number_format($amount / 100, 2) . ' ' . strtoupper($currency);
}

/**
 * 保存済みの日時文字列を <input type="datetime-local"> の value 形式
 * （YYYY-MM-DDTHH:MM）に変換する。解釈できなければ空文字（＝空欄表示）。
 */
function datetime_local_value(string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

/**
 * 指定パスが Web 公開領域（DOCUMENT_ROOT 配下）にあるか。
 * 判定不能（CLI 等で DOCUMENT_ROOT 不明）なら null。
 */
function path_within_docroot(string $path): ?bool
{
    $docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docroot === '') {
        return null;
    }
    $rd = realpath($docroot);
    if ($rd === false) {
        return null;
    }
    // ファイルが未作成でも親ディレクトリで判定する
    $target = realpath($path);
    if ($target === false) {
        $target = realpath(dirname($path));
        if ($target === false) {
            return null;
        }
    }
    $rd = rtrim($rd, '/') . '/';
    return $target === rtrim($rd, '/') || str_starts_with($target . '/', $rd);
}

/**
 * 指定URLのHTTPステータスを取得（1日キャッシュ）。判定不能なら null。
 * 露出の実測（誤検知防止）に使う。宛先は信頼できる APP_BASE_URL ベースのみ。
 */
function remote_status_cached(string $url): ?int
{
    $cacheFile = dirname(current_db_path()) . '/.webcheck.json';
    $map = [];
    if (is_file($cacheFile)) {
        $map = json_decode((string) @file_get_contents($cacheFile), true) ?: [];
    }
    $k = md5($url);
    if (isset($map[$k]['ts']) && (time() - (int) $map[$k]['ts'] < 86400)) {
        return $map[$k]['code'];
    }
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    $result = ($err === 0 && $code > 0) ? $code : null;
    $map[$k] = ['ts' => time(), 'code' => $result];
    @file_put_contents($cacheFile, json_encode($map));
    return $result;
}

/**
 * 指定ファイルが「Web から実際にダウンロードできる」かを実測する。
 * - 公開フォルダ(DOCUMENT_ROOT)の外なら false（そもそも到達不能＝安全）。
 * - 内側なら APP_BASE_URL からの相対URLを取得し 200 なら true（露出）。403/404 なら false。
 * 判定不能（CLI・APP_BASE_URL未設定・通信不可・ファイル未作成）は null。
 */
function file_web_downloadable(string $absPath): ?bool
{
    $base = rtrim((string) env('APP_BASE_URL', ''), '/');
    if ($base === '' || !preg_match('#^https?://#i', $base)) {
        return null;
    }
    $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $real = realpath($absPath);
    if ($docroot === false || $real === false) {
        return null;
    }
    $rd = rtrim($docroot, '/') . '/';
    if (!str_starts_with($real, $rd)) {
        return false; // 公開フォルダ外 → Web から取得不可
    }
    $rel = substr($real, strlen($rd));
    $code = remote_status_cached($base . '/' . str_replace('%2F', '/', rawurlencode($rel)));
    return $code === null ? null : ($code === 200);
}

/**
 * .env が実際に Web から取得できる状態か（誤検知防止のため実測）。
 */
function env_web_exposed(): ?bool
{
    $base = rtrim((string) env('APP_BASE_URL', ''), '/');
    if ($base === '' || !preg_match('#^https?://#i', $base)) {
        return null;
    }
    $code = remote_status_cached($base . '/.env');
    return $code === null ? null : ($code === 200);
}

/**
 * 重大な構成リスクを検知して返す（運用者へ警告するため）。
 * 「パスが公開フォルダ内」だけでは警告しない（.htaccess で守られている場合があるため）。
 * 実際に Web からダウンロードできる時だけ警告する。
 *
 * @return array<int, array{level:string, msg:string}>
 */
function security_warnings(): array
{
    $w = [];
    // DB（既定では Stripe 鍵も同じディレクトリ）が実際に Web から取得できる場合のみ警告。
    if (file_web_downloadable(current_db_path()) === true) {
        $w[] = [
            'level' => 'critical',
            'msg' => 'データベース（および Stripe 鍵）が Web から直接ダウンロードできる状態です。'
                . ' .htaccess を有効化するか、.env の DB_PATH を公開フォルダの外（例: /home/アカウント/private/app.sqlite）に設定してください。',
        ];
    }
    // .env が実際に取得できる場合のみ。
    if (env_web_exposed() === true) {
        $w[] = [
            'level' => 'critical',
            'msg' => '.env が Web から直接ダウンロードできる状態です（/.env が 200）。直ちに .htaccess を有効化するか、機密を公開フォルダの外へ移してください。',
        ];
    }
    return $w;
}

/**
 * 監査ログ（誰が・いつ・どこから・何をしたか）。
 * 公開フォルダ外（DB と同じ private 領域）に追記。秘密（鍵・カード情報・トークン）は記録しない。
 * 漏えい・不正アクセスの調査と、原因箇所を塞ぐための証跡として使う。
 *
 * @param array<string, scalar> $ctx 付帯情報（event_id, result 等。PII/秘密は入れないこと）
 */
function audit_log(string $event, array $ctx = []): void
{
    $path = dirname(current_db_path()) . '/audit.log';
    $max = (int) env('AUDIT_LOG_MAX_BYTES', '5242880'); // 5MB
    if ($max > 0 && is_file($path) && @filesize($path) >= $max) {
        @rename($path, $path . '.1'); // 1世代ローテーション
    }
    $parts = [];
    foreach ($ctx as $k => $v) {
        // 改行・空白を除去してログ1行を壊さない
        $parts[] = $k . '=' . preg_replace('/\s+/', '_', (string) $v);
    }
    $line = sprintf(
        "[%s] ip=%s ua=%s event=%s %s\n",
        date('c'),
        client_ip(),
        substr(preg_replace('/\s+/', '_', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '-')), 0, 60),
        $event,
        implode(' ', $parts)
    );
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * 出力エスケープ。
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/* =====================================================================
 * 管理（参加者管理）画面むけの共通処理
 *
 * 参加者の名簿は各テナントの Stripe（Checkout セッション／顧客）から都度取得する。
 * 管理画面の認証はテナントのセッションログイン（src/tenant.php）で行う。
 * ===================================================================== */

/**
 * CSRF トークンを取得（なければ生成）。セッションに保存する。
 */
function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 送信された CSRF トークンを検証。不一致なら 400 で終了。
 */
function csrf_verify(?string $token): void
{
    session_boot();
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
        audit_log('csrf.fail', ['path' => $_SERVER['SCRIPT_NAME'] ?? '']);
        http_response_code(400);
        exit('不正なリクエストです（CSRF トークン不一致）。画面を開き直してください。');
    }
}

/**
 * Connect 接続アカウント向けのリクエストオプションを返す。
 * $account が null の場合は空（プラットフォーム自身に対する操作）。
 *
 * @return array<string,string>
 */
function stripe_opts(?string $account): array
{
    return $account ? ['stripe_account' => $account] : [];
}

/**
 * 指定イベントの参加者一覧を Stripe から取得する（テナントの接続アカウント単位）。
 *
 * - 事前決済: 支払い済み Checkout セッション
 * - 当日支払い: metadata.payment_type=onsite の顧客（未収/集金済み）
 *
 * @param string      $eventId 対象イベント
 * @param string|null $account テナントの Stripe 接続アカウント（acct_...）。null なら自アカウント
 * @return array<int, array<string, mixed>>
 */
function fetch_event_participants(string $eventId, ?string $account = null): array
{
    init_stripe();
    $opts = stripe_opts($account);

    $participants = [];
    $params = [
        'limit' => 100,
        'expand' => ['data.payment_intent.latest_charge', 'data.customer'],
    ];

    foreach (\Stripe\Checkout\Session::all($params, $opts)->autoPagingIterator() as $session) {
        if (($session->metadata['event_id'] ?? null) !== $eventId) {
            continue;
        }
        if ($session->payment_status !== 'paid') {
            continue; // 未払い・中断セッションは名簿に含めない
        }

        // 参加者名: 自前フォームの metadata → Stripe カスタム項目 → 顧客情報の順で拾う
        $meta = $session->metadata ?? null;
        $name = $meta['participant_name'] ?? '';
        if ($name === '') {
            foreach (($session->custom_fields ?? []) as $field) {
                if (($field->key ?? '') === 'participant_name') {
                    $name = $field->text->value ?? '';
                    break;
                }
            }
        }
        if ($name === '') {
            $name = $session->customer_details->name ?? '';
        }

        // 自前フォームで集めた電話・参加人数・備考（metadata 優先）
        $phone = $meta['phone'] ?? ($session->customer_details->phone ?? '');
        $partySize = max(1, (int) ($meta['party_size'] ?? 1));
        $note = $meta['note'] ?? '';

        $pi = $session->payment_intent;            // expand 済みのオブジェクト
        $piId = is_object($pi) ? ($pi->id ?? '') : (string) $pi;
        $charge = is_object($pi) ? ($pi->latest_charge ?? null) : null;

        $amountRefunded = 0;
        $fullyRefunded = false;
        if (is_object($charge)) {
            $amountRefunded = (int) ($charge->amount_refunded ?? 0);
            $fullyRefunded = (bool) ($charge->refunded ?? false);
        }

        // 出席チェックは顧客の metadata.attended に保存する（事前・当日で共通）
        $customerObj = is_object($session->customer) ? $session->customer : null;
        $customerId = $customerObj ? ($customerObj->id ?? '') : (string) $session->customer;
        $attended = $customerObj ? (($customerObj->metadata['attended'] ?? '') === '1') : false;

        $participants[] = [
            'payment_type'    => 'prepay',   // 事前決済
            'session_id'      => $session->id,
            'payment_intent'  => $piId,
            'customer_id'     => $customerId,
            'name'            => $name,
            'email'           => $session->customer_details->email ?? '',
            'phone'           => $phone,
            'party_size'      => $partySize,
            'note'            => $note,
            'amount'          => (int) ($session->amount_total ?? 0),
            'currency'        => (string) ($session->currency ?? 'jpy'),
            'amount_refunded' => $amountRefunded,
            'fully_refunded'  => $fullyRefunded,
            'collected'       => false, // 事前決済では使わない（当日支払い用）
            'attended'        => $attended,
            'created'         => (int) ($session->created ?? 0),
        ];
    }

    // 当日支払いの申込者は「課金なしの Stripe 顧客（metadata.payment_type=onsite）」として記録される。
    // これらを名簿に合流させる（未収として表示）。
    foreach (\Stripe\Customer::all(['limit' => 100], $opts)->autoPagingIterator() as $customer) {
        $meta = $customer->metadata ?? null;
        if (($meta['event_id'] ?? null) !== $eventId) {
            continue;
        }
        if (($meta['payment_type'] ?? '') !== 'onsite') {
            continue;
        }

        $participants[] = [
            'payment_type'    => 'onsite',   // 当日支払い
            'session_id'      => '',
            'payment_intent'  => '',
            'customer_id'     => $customer->id,
            'name'            => $meta['participant_name'] ?? ($customer->name ?? ''),
            'email'           => $customer->email ?? '',
            'phone'           => $meta['phone'] ?? ($customer->phone ?? ''),
            'party_size'      => max(1, (int) ($meta['party_size'] ?? 1)),
            'note'            => $meta['note'] ?? '',
            'amount'          => (int) ($meta['onsite_total'] ?? 0),
            'currency'        => (string) ($meta['currency'] ?? 'jpy'),
            'amount_refunded' => 0,
            'fully_refunded'  => false,
            'collected'       => (($meta['collected'] ?? '') === '1'), // 当日分の受領（集金）済みか
            'attended'        => (($meta['attended'] ?? '') === '1'),  // 出席確認済みか
            'created'         => (int) ($customer->created ?? 0),
        ];
    }

    // 申込日時の新しい順
    usort($participants, static fn ($a, $b) => $b['created'] <=> $a['created']);

    return $participants;
}

/**
 * 指定イベントの参加者の中から customer_id 一致を返す（無ければ null）。
 * 出席/集金/当日取消の操作対象が「本当にそのイベントの参加者か」を検証するために使う
 * （全テナントが単一 Stripe アカウントを共有するため、ID だけでは他テナントの顧客も指せてしまう＝IDOR 対策）。
 *
 * @return array<string,mixed>|null
 */
function find_event_participant_by_customer(string $eventId, ?string $account, string $customerId): ?array
{
    if ($customerId === '') {
        return null;
    }
    foreach (fetch_event_participants($eventId, $account) as $p) {
        if (($p['customer_id'] ?? '') === $customerId) {
            return $p;
        }
    }
    return null;
}

/**
 * 指定イベントの参加者の中から payment_intent 一致を返す（無ければ null）。返金の IDOR 対策に使う。
 *
 * @return array<string,mixed>|null
 */
function find_event_participant_by_payment_intent(string $eventId, ?string $account, string $paymentIntent): ?array
{
    if ($paymentIntent === '') {
        return null;
    }
    foreach (fetch_event_participants($eventId, $account) as $p) {
        if (($p['payment_intent'] ?? '') === $paymentIntent) {
            return $p;
        }
    }
    return null;
}

/**
 * CSV セルの数式インジェクション対策。
 * 先頭が = + - @ または制御文字（Tab/CR）で始まる値は、Excel/Sheets が数式として
 * 解釈・実行しないよう先頭にシングルクオートを付けて無害化する。
 */
function csv_cell(?string $value): string
{
    $value = (string) $value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }
    return $value;
}
