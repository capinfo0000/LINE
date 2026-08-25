<?php

/**
 * アプリ共通の初期化処理（単一運営）。
 *
 * 【重要・設計思想】
 * このアプリのサーバー（PHP）は、クレジットカード情報を一切受け取らず・保存しません。
 * カード番号・有効期限・セキュリティコードの入力は、すべて Stripe がホストする
 * 決済ページ（Stripe Checkout）上で行われます。PCI DSS 準拠は Stripe 側の責任範囲です。
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';

// 表示・比較に使う既定のタイムゾーンを固定する。
// これまで各所で date() に手で +9時間していたが、それはサーバの date.timezone が
// UTC のときだけ正しく、Asia/Tokyo のサーバでは9時間ずれる（同じ枠の日時が
// LINEのカードと通知本文で食い違う原因になっていた）。ここで一度だけ決めて、
// 以降は素直に date() を使う。Zoom API に渡す UTC は gmdate() のままにする。
date_default_timezone_set('Asia/Tokyo');

// データ層・認証・メール・CAPTCHA・暗号のヘルパー。関数定義のみで、呼び出し時に env() を使う。
require __DIR__ . '/announce.php';
require __DIR__ . '/db.php';
require __DIR__ . '/tenant.php';
require __DIR__ . '/member.php';
require __DIR__ . '/profile.php';
require __DIR__ . '/directory.php';
require __DIR__ . '/match.php';
require __DIR__ . '/payment.php';
require __DIR__ . '/plan.php';
require __DIR__ . '/zoom.php';
require __DIR__ . '/booking.php';
require __DIR__ . '/points.php';
require __DIR__ . '/samples.php';
require __DIR__ . '/line.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/legal.php';
require __DIR__ . '/meta.php';
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
 * script はインライン禁止（自ホスト＋nonce のみ）で XSS 耐性を確保する。
 */
function send_baseline_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    // CAPTCHA(Turnstile)有効時は、そのウィジェット配信元を許可リストに加える。
    $captchaHost = captcha_enabled() ? ' https://challenges.cloudflare.com' : '';
    $nonce = "'nonce-" . csp_nonce() . "'";
    // img-src に blob: を許可：ファイル選択直後のプレビュー（URL.createObjectURL）を表示するため。
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; "
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
 * 環境変数を取得。未設定なら $default。
 */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

/** 必須の環境変数。未設定なら 500 で停止する。 */
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
 * このアプリの公開ベースURL（success/cancel/webhook の組み立てに使用）。
 * ローカル開発では APP_BASE_URL=http://localhost:8000 を想定。
 */
function base_url(): string
{
    return rtrim(env('APP_BASE_URL', 'http://localhost:8000'), '/');
}

/**
 * Stripe SDK を初期化（プラットフォーム＝単一運営の秘密鍵を使用）。
 * 決済系（入会金 Checkout / Webhook）から呼び出す。鍵未設定なら例外。
 */
function init_stripe(): void
{
    $key = env('STRIPE_SECRET_KEY');
    if ($key === null) {
        throw new \RuntimeException('Stripe の鍵（STRIPE_SECRET_KEY）が設定されていません。');
    }
    \Stripe\Stripe::setApiKey($key);
}

/**
 * 金額を「¥3,000」形式に整形（JPYは最小通貨単位＝円なのでそのまま）。
 */
function format_amount(int $amount, string $currency = 'jpy'): string
{
    if (strtolower($currency) === 'jpy') {
        return '¥' . number_format($amount);
    }
    return number_format($amount / 100, 2) . ' ' . strtoupper($currency);
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
    // DB（会員PII・決済照合データ）が実際に Web から取得できる場合のみ警告。
    if (file_web_downloadable(current_db_path()) === true) {
        $w[] = [
            'level' => 'critical',
            'msg' => 'データベースが Web から直接ダウンロードできる状態です。'
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
 *
 * @param array<string, scalar> $ctx 付帯情報（PII/秘密は入れないこと）
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
        // 同一ホストの参照元にだけ「戻る」を許可（オープンリダイレクト対策）。
        $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $safe = '/';
        // ポートを含む HTTP_HOST とホスト名のみの parse_url を揃えて比較（同一オリジンのみ許可）。
        $host = parse_url('//' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if ($back !== '' && parse_url($back, PHP_URL_HOST) === $host) {
            $safe = $back;
        }
        header('Content-Type: text/html; charset=UTF-8');
        exit('<!doctype html><html lang="ja"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>送信できませんでした</title><link rel="stylesheet" href="/assets/app.css"></head>'
            . '<body><div class="container container--narrow" style="padding-top:40px;">'
            . '<div class="flash flash--ng"><strong>送信できませんでした。</strong><br>'
            . 'フォームを長い時間開いたままにしていた可能性があります。'
            . 'また、アップロードした画像が大きすぎる場合にも起こることがあります。</div>'
            . '<p class="muted" style="font-size:.85rem;">お手数ですが、画面を開き直してもう一度お試しください。'
            . '画像を送る場合は、少し小さめの画像でお試しください。</p>'
            . '<p><a class="btn" href="' . e($safe) . '">前の画面に戻る</a></p>'
            . '</div></body></html>');
    }
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
