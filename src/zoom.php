<?php

/**
 * Zoom 連携（Server-to-Server OAuth・社内利用）。
 *
 * 予約確定時に会議を自動作成し join_url を得る。アクセストークンは短命なのでファイルにキャッシュし、
 * 失効時に再取得する。未設定・失敗時は null を返し、呼び出し側は「枠だけ確定＋手動URL案内」に
 * フォールバックする（会議自動作成の失敗で予約全体を落とさない）。
 */

declare(strict_types=1);

const ZOOM_OAUTH_ENDPOINT = 'https://zoom.us/oauth/token';
const ZOOM_API_BASE = 'https://api.zoom.us/v2';

/** Zoom 連携に必要な資格情報が揃っているか。 */
function zoom_enabled(): bool
{
    return env('ZOOM_ACCOUNT_ID') !== null
        && env('ZOOM_CLIENT_ID') !== null
        && env('ZOOM_CLIENT_SECRET') !== null;
}

/**
 * アクセストークンを取得（キャッシュ付き）。失敗なら null。
 */
function zoom_access_token(): ?string
{
    if (!zoom_enabled()) {
        return null;
    }
    $cacheFile = dirname(current_db_path()) . '/.zoom_token.json';
    if (is_file($cacheFile)) {
        $cache = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cache) && isset($cache['token'], $cache['expires_at']) && (int) $cache['expires_at'] > time() + 60) {
            return (string) $cache['token'];
        }
    }
    if (!function_exists('curl_init')) {
        return null;
    }
    $accountId = trim((string) env('ZOOM_ACCOUNT_ID'));
    $clientId = trim((string) env('ZOOM_CLIENT_ID'));
    $clientSecret = trim((string) env('ZOOM_CLIENT_SECRET'));

    $ch = curl_init(ZOOM_OAUTH_ENDPOINT . '?grant_type=account_credentials&account_id=' . urlencode($accountId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $clientId . ':' . $clientSecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => '',
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($err !== 0 || $code < 200 || $code >= 300) {
        error_log("Zoom OAuth error code={$code} err={$err}");
        return null;
    }
    $data = json_decode((string) $resp, true);
    $token = $data['access_token'] ?? null;
    $expiresIn = (int) ($data['expires_in'] ?? 3600);
    if (!is_string($token) || $token === '') {
        return null;
    }
    @file_put_contents($cacheFile, json_encode(['token' => $token, 'expires_at' => time() + $expiresIn]));
    @chmod($cacheFile, 0600);
    return $token;
}

/**
 * 会議を作成し ['id'=>..., 'join_url'=>...] を返す。失敗・未設定なら null（フォールバック）。
 *
 * @param string $topic     会議名
 * @param int    $startAtTs 開始時刻（UNIX秒）
 * @param int    $duration  分
 */
function zoom_create_meeting(string $topic, int $startAtTs, int $duration = 40): ?array
{
    $token = zoom_access_token();
    if ($token === null) {
        return null;
    }
    $payload = [
        'topic' => $topic,
        'type' => 2, // scheduled
        'start_time' => gmdate('Y-m-d\TH:i:s\Z', $startAtTs),
        'duration' => max(1, $duration),
        'timezone' => 'Asia/Tokyo',
        'settings' => [
            'join_before_host' => true,
            'waiting_room' => true,
            'approval_type' => 2,
        ],
    ];
    $ch = curl_init(ZOOM_API_BASE . '/users/me/meetings');
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
        error_log("Zoom create meeting error code={$code} err={$err} resp=" . substr((string) $resp, 0, 300));
        return null;
    }
    $data = json_decode((string) $resp, true);
    $joinUrl = $data['join_url'] ?? null;
    $id = $data['id'] ?? null;
    if (!is_string($joinUrl) || $joinUrl === '') {
        return null;
    }
    return ['id' => (string) $id, 'join_url' => $joinUrl];
}

/**
 * Zoom 接続診断。設定の有無 → トークン取得 → API 呼び出し の順に検証し、詰まった箇所と
 * HTTP コード・理由・対処ヒントを返す。管理画面の「Zoom接続テスト」から呼ぶ。
 *
 * @return array{ok:bool, message:string}
 */
function zoom_diagnose(): array
{
    $missing = [];
    foreach (['ZOOM_ACCOUNT_ID', 'ZOOM_CLIENT_ID', 'ZOOM_CLIENT_SECRET'] as $k) {
        $v = env($k);
        if ($v === null || $v === '') {
            $missing[] = $k;
        }
    }
    if ($missing !== []) {
        return ['ok' => false, 'message' => '.env のZoom設定が不足しています: ' . implode(' / ', $missing) . '（3つとも必要です）'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'サーバに cURL 拡張がありません。ホスト側で有効化が必要です。'];
    }

    // 1) アクセストークン取得（キャッシュを使わず生で検証）。前後空白は除去。
    $accountId = trim((string) env('ZOOM_ACCOUNT_ID'));
    $clientId = trim((string) env('ZOOM_CLIENT_ID'));
    $clientSecret = trim((string) env('ZOOM_CLIENT_SECRET'));
    $ch = curl_init(ZOOM_OAUTH_ENDPOINT . '?grant_type=account_credentials&account_id=' . urlencode($accountId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $clientId . ':' . $clientSecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => '',
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'message' => "Zoom(zoom.us)へ接続できません（ネットワーク/プロキシ）: {$curlErr}"];
    }
    $data = json_decode((string) $resp, true);
    if ($code < 200 || $code >= 300 || !is_array($data) || !isset($data['access_token'])) {
        $reason = is_array($data) ? (string) ($data['reason'] ?? $data['error'] ?? '') : '';
        $hint = '';
        if ($code === 400 || $code === 401) {
            $hint = ' → アプリ種別が「Server-to-Server OAuth」であること、Account ID / Client ID / Client Secret が正しいことを確認してください。';
        }
        // 設定値の文字数を出して原因特定を助ける（正しい長さは概ね account=22 / client=22 / secret=32）。
        $lenInfo = sprintf('（設定値の文字数: account=%d, client=%d, secret=%d）', strlen($accountId), strlen($clientId), strlen($clientSecret));
        return ['ok' => false, 'message' => "トークン取得に失敗（HTTP {$code} {$reason}）。{$hint}{$lenInfo}"];
    }

    // 2) 会議作成に必要な meeting:write スコープが付与されているかをトークンの scope で確認する。
    //    （実際の会議作成は POST /users/me/meetings + meeting:write のみで動作。user:read は不要。）
    $scope = (string) ($data['scope'] ?? '');
    if (strpos($scope, 'meeting:write') === false) {
        return ['ok' => false, 'message' => '認証は成功しましたが、会議作成に必要な meeting:write スコープが付与されていません。Zoomアプリのスコープに meeting:write（meeting:write:meeting:admin 等）を追加して有効化してください。'];
    }

    return ['ok' => true, 'message' => 'Zoom接続OK。認証と会議作成スコープ(meeting:write)を確認しました。枠作成で会議URLが自動発行されます。'];
}
