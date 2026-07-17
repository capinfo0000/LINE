<?php

/**
 * 会員アカウント・会員ログイン・発行ID/PW・PW再設定のヘルパー（会員サイト用）。
 *
 * 運営者（src/tenant.php）とは別のセッションキー（member_id）で認証する。
 * 会員は「発行ログインID（login_id）＋パスワード」でログインし、初回はPW強制変更。
 * ブルートフォース対策（login_attempts）・レート制限（rate_events）は既存ヘルパーを流用する。
 */

declare(strict_types=1);

/** 会員セッションのアイドルタイムアウト（秒）。最終操作からこの時間で失効。 */
const MEMBER_IDLE_TIMEOUT = 3600; // 60分

/* ------------------------- ID/PW 発行 ------------------------- */

/** 重複しない会員IDを生成する。 */
function generate_member_id(): string
{
    return 'mem_' . bin2hex(random_bytes(6));
}

/**
 * 紛らわしい文字（0/o/1/l/i など）を避けた安全な英数字ランダム文字列。
 */
function random_safe_string(int $len, string $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'): string
{
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** 重複しない発行ログインID（例 el8f3k9q2m）を生成する。 */
function generate_member_login_id(): string
{
    do {
        $id = 'el' . random_safe_string(8);
    } while (find_member_by_login_id($id) !== null);
    return $id;
}

/**
 * 会員に渡す仮パスワードを生成する（読みやすく、強度チェックを満たす）。
 * 記号を1つ混ぜ、英大文字・小文字・数字を含める。
 */
function generate_temp_password(): string
{
    $upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $digit = '23456789';
    $pw = $upper[random_int(0, strlen($upper) - 1)]
        . $lower[random_int(0, strlen($lower) - 1)]
        . $digit[random_int(0, strlen($digit) - 1)]
        . random_safe_string(6, $upper . $lower . $digit);
    // 並びをシャッフル
    $arr = str_split($pw);
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
    }
    return implode('', $arr);
}

/**
 * 会員アカウントを新規作成し、発行ログインID＋仮パスワードを返す。
 * 初回PW強制変更（must_change_pw=1）で作成する。入金Webhook（Phase 2）や
 * 運用CLIから呼び出して資格情報を配布する。
 *
 * @return array{member_id:string, login_id:string, temp_password:string}
 */
function issue_member_credentials(
    ?string $email = null,
    ?string $displayName = null,
    string $status = 'active',
    ?string $lineUserId = null
): array {
    $memberId = generate_member_id();
    $loginId = generate_member_login_id();
    $tempPassword = generate_temp_password();
    $email = ($email !== null && trim($email) !== '') ? strtolower(trim($email)) : null;

    $stmt = db()->prepare(
        'INSERT INTO members (id, login_id, password_hash, must_change_pw, display_name, email, line_user_id, status, joined_at, created_at)
         VALUES (:id, :login_id, :hash, 1, :name, :email, :line, :status, :joined, :created)'
    );
    $stmt->execute([
        ':id'       => $memberId,
        ':login_id' => $loginId,
        ':hash'     => password_hash($tempPassword, PASSWORD_DEFAULT),
        ':name'     => $displayName !== null ? mb_substr(trim($displayName), 0, 100) : '',
        ':email'    => $email,
        ':line'     => $lineUserId,
        ':status'   => $status,
        ':joined'   => $status === 'active' ? time() : null,
        ':created'  => time(),
    ]);

    return ['member_id' => $memberId, 'login_id' => $loginId, 'temp_password' => $tempPassword];
}

/* ------------------------- 参照 ------------------------- */

function find_member_by_login_id(string $loginId): ?array
{
    $stmt = db()->prepare('SELECT * FROM members WHERE login_id = ?');
    $stmt->execute([strtolower(trim($loginId))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_member_by_id(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_member_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM members WHERE email = ? ORDER BY created_at LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 会員がログイン可能な状態か（有効会員のみ）。 */
function member_can_login(array $member): bool
{
    return ($member['status'] ?? '') === 'active';
}

/* ------------------------- ログイン ------------------------- */

/**
 * 発行ログインID＋パスワードでログイン。成功でセッションに保存し true。
 * ステータスが active 以外（停止・退会・未入金）は false。
 */
function login_member(string $loginId, string $password): bool
{
    // タイミング攻撃によるID列挙対策: 未知IDでも bcrypt 検証を1回行い応答時間を平準化する。
    $dummyHash = '$2y$12$iOI7xMnDX6U9v5ZKJ/SC1O4K8KEa/DBdKX6/VaaIg3PcM5nyTymFq';
    $member = find_member_by_login_id($loginId);
    if ($member === null) {
        password_verify($password, $dummyHash);
        return false;
    }
    if (!password_verify($password, $member['password_hash'])) {
        return false;
    }
    if (!member_can_login($member)) {
        return false;
    }
    session_boot();
    session_regenerate_id(true);
    $_SESSION['member_id'] = $member['id'];
    $_SESSION['member_last_activity'] = time();
    return true;
}

function logout_member(): void
{
    session_boot();
    unset($_SESSION['member_id'], $_SESSION['member_last_activity']);
}

/** 現在ログイン中の会員（未ログイン・失効なら null）。 */
function current_member(): ?array
{
    session_boot();
    $id = $_SESSION['member_id'] ?? '';
    if ($id === '') {
        return null;
    }
    // アイドルタイムアウト
    $now = time();
    $last = (int) ($_SESSION['member_last_activity'] ?? $now);
    if ($now - $last > MEMBER_IDLE_TIMEOUT) {
        logout_member();
        return null;
    }
    $member = find_member_by_id($id);
    if ($member === null || !member_can_login($member)) {
        logout_member();
        return null;
    }
    $_SESSION['member_last_activity'] = $now;
    return $member;
}

/**
 * 会員ログイン必須。未ログインならログイン画面へ。
 * 初回PW強制変更が必要な場合は、変更ページ以外へのアクセスを change_password.php へ誘導する。
 */
function require_member(bool $allowDuringPwChange = false): array
{
    $member = current_member();
    if ($member === null) {
        header('Location: /member/login.php');
        exit;
    }
    if (!$allowDuringPwChange && (int) ($member['must_change_pw'] ?? 0) === 1) {
        header('Location: /member/change_password.php');
        exit;
    }
    return $member;
}

/* ------------------------- パスワード変更／再設定 ------------------------- */

/** 会員のパスワードを更新する（強度チェックあり）。初回強制変更フラグも解除する。 */
function update_member_password(string $memberId, string $newPassword): void
{
    assert_password_strength($newPassword);
    $stmt = db()->prepare('UPDATE members SET password_hash = ?, must_change_pw = 0 WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $memberId]);
}

/**
 * 会員向けパスワード再設定トークンを発行する。存在しないメールでも例外にせず null。
 * （アカウントの有無を外部に漏らさないため）
 */
function create_member_password_reset(string $email, int $ttlSec = 3600): ?string
{
    $member = find_member_by_email($email);
    if ($member === null) {
        return null;
    }
    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO member_password_resets (token, member_id, expires_at, created_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$token, $member['id'], time() + $ttlSec, time()]);
    return $token;
}

/** 有効な会員再設定トークンに対応するレコードを返す。無効なら null。 */
function find_valid_member_reset(string $token): ?array
{
    $stmt = db()->prepare('SELECT * FROM member_password_resets WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['used'] === 1 || (int) $row['expires_at'] < time()) {
        return null;
    }
    return $row;
}

/** トークンを使って会員パスワードを再設定する。成功で true。 */
function consume_member_password_reset(string $token, string $newPassword): bool
{
    $reset = find_valid_member_reset($token);
    if ($reset === null) {
        return false;
    }
    update_member_password($reset['member_id'], $newPassword);
    $stmt = db()->prepare('UPDATE member_password_resets SET used = 1 WHERE token = ?');
    $stmt->execute([$token]);
    return true;
}
