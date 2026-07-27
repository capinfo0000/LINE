<?php

/**
 * 保存データの暗号化ヘルパー（主催者ごとの Stripe 秘密鍵を at-rest 暗号化するため）。
 *
 * APP_KEY（.env の base64 32バイト）を鍵に AES-256-GCM で暗号化する。
 * APP_KEY は DB とは別管理（.env）なので、DB 単体が漏れても復号できない。
 */

declare(strict_types=1);

/** APP_KEY（生バイト32）を返す。未設定/不正なら null。 */
function app_key(): ?string
{
    $b64 = env('APP_KEY');
    if ($b64 === null) {
        return null;
    }
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) !== 32) {
        return null;
    }
    return $raw;
}

/** 暗号化が利用可能か（APP_KEY が正しく設定されているか）。 */
function crypto_available(): bool
{
    return app_key() !== null;
}

/**
 * APP_KEY が無ければ自動生成して .env に保存し、当該プロセスでも有効化する。
 * これにより、主催者の鍵を常に「暗号化」で保存できる（平文ディスク保存を避ける）。
 * .env が書き込めない等で用意できなければ false（呼び出し側は平文フォールバック）。
 */
function ensure_app_key(): bool
{
    if (app_key() !== null) {
        return true;
    }
    $key = base64_encode(random_bytes(32));
    $path = APP_ROOT . '/.env';
    $writable = is_file($path) ? is_writable($path) : is_writable(dirname($path));
    if (!$writable) {
        return false;
    }
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    $found = false;
    foreach ($lines as $i => $l) {
        if (preg_match('/^\s*APP_KEY\s*=/', $l)) {
            $lines[$i] = 'APP_KEY=' . $key;
            $found = true;
        }
    }
    if (!$found) {
        $lines[] = 'APP_KEY=' . $key;
    }
    if (@file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        return false;
    }
    putenv('APP_KEY=' . $key);
    $_ENV['APP_KEY'] = $key;
    return app_key() !== null;
}

/**
 * 平文を暗号化して base64 文字列を返す。APP_KEY 未設定なら例外。
 * 形式: base64( iv(12) || tag(16) || ciphertext )
 */
function app_encrypt(string $plaintext): string
{
    $key = app_key();
    if ($key === null) {
        throw new \RuntimeException('APP_KEY が未設定のため暗号化できません。');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new \RuntimeException('暗号化に失敗しました。');
    }
    return base64_encode($iv . $tag . $cipher);
}

/**
 * app_encrypt で作った文字列を復号する。失敗時は null。
 */
function app_decrypt(string $blob): ?string
{
    $key = app_key();
    if ($key === null) {
        return null;
    }
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}
