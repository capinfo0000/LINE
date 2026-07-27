<?php

/**
 * CAPTCHA（ボット対策）ヘルパー — Cloudflare Turnstile 対応。
 *
 * 環境変数 TURNSTILE_SITE_KEY と TURNSTILE_SECRET_KEY の両方が設定されているときだけ有効化する。
 * 未設定なら何もせず素通り（既存の動作を壊さない）。レート制限と併用して未認証フォームの濫用を抑止する。
 */

declare(strict_types=1);

/** CAPTCHA が有効か（サイトキー・シークレットの両方が設定済みか）。 */
function captcha_enabled(): bool
{
    return env('TURNSTILE_SITE_KEY') !== null && env('TURNSTILE_SECRET_KEY') !== null;
}

/** Turnstile ウィジェット（フォーム内に出力する HTML）。無効時は空文字。 */
function captcha_widget_html(): string
{
    if (!captcha_enabled()) {
        return '';
    }
    $siteKey = e((string) env('TURNSTILE_SITE_KEY'));
    return '<div class="cf-turnstile" data-sitekey="' . $siteKey . '" style="margin:12px 0;"></div>'
        . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
}

/**
 * 送信された CAPTCHA トークンを検証する。無効化時（キー未設定）は常に true。
 *
 * @param bool $failClosed 検証サービスに到達できないときの挙動。
 *   true  = 失敗扱いで拒否（signup/login 等、悪用時の被害が大きい入口向け）。
 *   false = 通過（apply/forgot 等、可用性を優先する入口向け）。
 */
function captcha_verify(?string $token, bool $failClosed = false): bool
{
    if (!captcha_enabled()) {
        return true;
    }
    if ($token === null || $token === '') {
        return false;
    }

    $secret = (string) env('TURNSTILE_SECRET_KEY');
    $postData = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);

    if ($err !== 0 || !is_string($resp)) {
        // 検証サービスに到達できないとき: failClosed なら拒否、そうでなければ可用性優先で通す。
        error_log('CAPTCHA 検証の通信失敗: curl errno ' . $err);
        return !$failClosed;
    }
    $data = json_decode($resp, true);
    return is_array($data) && !empty($data['success']);
}
