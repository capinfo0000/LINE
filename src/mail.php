<?php

/**
 * メール送信ヘルパー（最小実装）。
 *
 * PHP の mail() を使い、併せて logs/mail.log にも追記する（運用・開発確認用）。
 * 本番で確実に届けたい場合は、サーバーの MTA 設定、または SMTP/外部送信サービスへの
 * 差し替えを推奨（この関数 1 か所を変えれば全体に反映される）。
 */

declare(strict_types=1);

/**
 * メールを送信する。送信可否に関わらず logs/mail.log に記録する。
 */
function send_mail(string $to, string $subject, string $body): bool
{
    $fromAddr = env('MAIL_FROM', 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $fromName = env('MAIL_FROM_NAME', 'イベント事前決済');

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . $encodedFromName . ' <' . $fromAddr . '>',
    ];

    $sent = @mail($to, $encodedSubject, $body, implode("\r\n", $headers));

    // 監査用ログ（既定オン）。個人情報・本文・トークンは残さない。
    // 記録するのは「いつ・どの宛先（マスク）・件名・送信可否」のみ。MAIL_LOG=0 で完全無効化。
    if (env('MAIL_LOG', '1') !== '0') {
        $logPath = APP_ROOT . '/logs/mail.log';
        rotate_log_if_large($logPath);
        $logLine = sprintf(
            "[%s] to=%s subject=%s sent=%d\n",
            date('c'),
            mask_email_for_log($to),
            mask_subject_for_log($subject),
            $sent ? 1 : 0
        );
        @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
    }

    return $sent;
}

/**
 * ログ用に件名から具体名（イベント名など）を伏せる。
 * 「【…】<イベント名>（…）」の <イベント名> 部分を … に置換。該当が無ければそのまま。
 */
function mask_subject_for_log(string $subject): string
{
    return (string) preg_replace('/】.*?(（|$)/u', '】…$1', $subject);
}

/**
 * ログ用にメールアドレスをマスクする（ローカル部の先頭1文字＋ドメインのみ残す）。
 * 例: yamada.taro@example.com → y***@example.com
 */
function mask_email_for_log(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false || $at === 0) {
        return '***';
    }
    return substr($email, 0, 1) . '***' . substr($email, $at);
}

/**
 * ログファイルが上限を超えていたら 1 世代だけローテーションする（.1 に退避）。
 * 上限は MAIL_LOG_MAX_BYTES（既定 5MB）。0 以下なら無効。
 */
function rotate_log_if_large(string $path): void
{
    $max = (int) env('MAIL_LOG_MAX_BYTES', '5242880'); // 5MB
    if ($max <= 0 || !is_file($path)) {
        return;
    }
    if (filesize($path) >= $max) {
        @rename($path, $path . '.1'); // 既存の .1 は上書き（保持は1世代）
    }
}
