<?php

/**
 * 主催者（テナント）アカウント・招待・ログインのヘルパー。
 * 認証はサーバーサイドのセッションで行う（管理画面の Basic 認証を置き換える）。
 */

declare(strict_types=1);

/** ログインセッションのアイドルタイムアウト（秒）。最終操作からこの時間で失効。 */
const SESSION_IDLE_TIMEOUT = 1800; // 30分

/** セッションを開始（未開始なら）。Cookie を堅牢化してから開始する。 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // セッションファイルを公開領域外の専用ディレクトリに隔離する。
    // 共有ホスティングで保存先が共有・覗き見可能な場合のセッション窃取（→名簿PII流出）を防ぐ。
    $sessDir = dirname(current_db_path()) . '/sessions';
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0700, true);
    }
    if (is_dir($sessDir) && is_writable($sessDir)) {
        session_save_path($sessDir);
        // PHP の自動掃除（session.gc_probability）が 0 の環境では、期限切れの
        // セッションファイルが消えずに溜まり続ける。保存先を自前のディレクトリに
        // 移しているため、OS側の掃除（Debian系の cron 等）も届かない。
        // 時々ここで自分で消す。確率で走らせるのは rate_events の掃除と同じ考え方。
        if (random_int(1, 100) === 1) {
            session_files_cleanup($sessDir);
        }
    }
    // 未知のセッションIDを受け取ったときに、それを使い回さず新しく発行する。
    // 攻撃者が用意したIDを踏ませてからログインさせる手口（セッション固定）の入口を塞ぐ。
    // ログイン時に session_regenerate_id(true) もしているので二重に防ぐ形になる。
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // HTTPS 配信（プロキシ経由・APP_BASE_URL が https を含む）なら Secure 属性を常時付与
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => request_is_https(),
        'samesite' => 'Lax',
    ]);
    session_start();

    // ログイン中セッションのアイドルタイムアウト（最終操作から一定時間で強制ログアウト）
    if (!empty($_SESSION['tenant_id'])) {
        $now = time();
        $last = (int) ($_SESSION['last_activity'] ?? $now);
        if ($now - $last > SESSION_IDLE_TIMEOUT) {
            $_SESSION = [];
            session_destroy();
            return;
        }
        $_SESSION['last_activity'] = $now;
    }
}

/**
 * 期限切れのセッションファイルを消す。
 *
 * 消す基準は「最後に触られてから、アプリのアイドルタイムアウトを超えたもの」。
 * 会員60分・運営30分なので、長いほうに余裕を足した時間より古いものは、
 * もうログイン状態として使えない（current_member / current_tenant が弾く）。
 * 使えないファイルを置いたままにする理由が無いので消す。
 *
 * 1回で消す数に上限を付ける。溜まったファイルが大量にあるとき、
 * 1リクエストの中で全部消そうとすると応答が遅くなるため。
 */
function session_files_cleanup(string $dir, int $maxDelete = 200): int
{
    $keep = max(MEMBER_IDLE_TIMEOUT, SESSION_IDLE_TIMEOUT) + 3600; // 余裕1時間
    $cut = time() - $keep;
    $n = 0;
    $dh = @opendir($dir);
    if ($dh === false) {
        return 0;
    }
    while (($f = readdir($dh)) !== false) {
        if ($n >= $maxDelete) {
            break;
        }
        // PHP が作るファイルだけを対象にする（他のファイルを消さない）。
        if (strncmp($f, 'sess_', 5) !== 0) {
            continue;
        }
        $path = $dir . '/' . $f;
        $mt = @filemtime($path);
        if ($mt !== false && $mt < $cut && @unlink($path)) {
            $n++;
        }
    }
    closedir($dh);
    return $n;
}

/* ------------------- ログイン試行の制限（総当たり対策） ------------------- */

/** 直近 $windowSec 秒の失敗回数（メール単位）。 */
function recent_failed_logins(string $email, int $windowSec = 900): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND created_at >= ?');
    $stmt->execute([strtolower(trim($email)), time() - $windowSec]);
    return (int) $stmt->fetchColumn();
}

/** 直近 $windowSec 秒の失敗回数（IP単位）。メール横断のスプレー攻撃対策。 */
function recent_failed_logins_by_ip(string $ip, int $windowSec = 900): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= ?');
    $stmt->execute([$ip, time() - $windowSec]);
    return (int) $stmt->fetchColumn();
}

/** 失敗を記録する。IPは判定側 recent_failed_logins_by_ip(client_ip()) と揃える。 */
function record_failed_login(string $email): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (identifier, ip, created_at) VALUES (?, ?, ?)');
    $stmt->execute([strtolower(trim($email)), client_ip(), time()]);
}

/** 成功時に失敗履歴をクリアする。 */
function clear_failed_logins(string $email): void
{
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE identifier = ?');
    $stmt->execute([strtolower(trim($email))]);
}

/* ------------------- 汎用レート制限（未認証エンドポイントの濫用対策） ------------------- */

/**
 * 現在の送信元IPを返す（取得できなければ 'unknown'）。
 *
 * 既定では REMOTE_ADDR を使う（XFF はクライアントが偽装できるため信用しない）。
 * 信頼できるリバースプロキシ/CDN の背後では .env に TRUSTED_PROXY=1 を設定すると、
 * X-Forwarded-For の先頭（最も外側のクライアント）を採用する。これにより
 * 「全員が上流IPで1バケットに集約されて誤ロック/制限無効化される」問題を避ける。
 */
function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (env('TRUSTED_PROXY') !== null && env('TRUSTED_PROXY') !== '0') {
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
    }
    return $remote;
}

/**
 * 直近 $windowSec 秒の $action 実行回数（identifier 単位）を数え、$max 未満なら許可して記録する。
 * 上限に達していれば記録せず false を返す（＝ブロック）。$identifier 省略時は送信元IP。
 */
function rate_limit_check(string $action, int $max, int $windowSec, ?string $identifier = null): bool
{
    $id = $identifier ?? client_ip();
    $since = time() - $windowSec;

    $stmt = db()->prepare('SELECT COUNT(*) FROM rate_events WHERE action = ? AND identifier = ? AND created_at >= ?');
    $stmt->execute([$action, $id, $since]);
    if ((int) $stmt->fetchColumn() >= $max) {
        return false;
    }

    $ins = db()->prepare('INSERT INTO rate_events (action, identifier, created_at) VALUES (?, ?, ?)');
    $ins->execute([$action, $id, time()]);

    // 古いレコードを時々掃除（肥大防止）。確率的に実行。
    if (random_int(1, 50) === 1) {
        $del = db()->prepare('DELETE FROM rate_events WHERE created_at < ?');
        $del->execute([time() - 86400]);
    }
    return true;
}

/* ------------------------- テナント ------------------------- */

function generate_tenant_id(): string
{
    return 'tn_' . bin2hex(random_bytes(6));
}

/**
 * パスワード強度を検証する。満たさなければ InvalidArgumentException。
 * - 8文字以上
 * - よくある脆弱なパスワード・単一文字の繰り返しを拒否
 */
function assert_password_strength(string $password): void
{
    if (strlen($password) < 8) {
        throw new \InvalidArgumentException('パスワードは8文字以上にしてください。');
    }
    $lower = strtolower($password);
    $weak = [
        'password', 'passw0rd', '12345678', '123456789', '1234567890',
        'qwerty', 'qwertyui', 'abc12345', 'test1234', 'admin123',
        '11111111', '00000000', 'iloveyou', 'letmein1', 'welcome1',
    ];
    if (in_array($lower, $weak, true)) {
        throw new \InvalidArgumentException('このパスワードは推測されやすいため使用できません。別のパスワードにしてください。');
    }
    // 同一文字の繰り返しのみ（例: aaaaaaaa）を拒否
    if (preg_match('/^(.)\1+$/', $password)) {
        throw new \InvalidArgumentException('このパスワードは単純すぎます。別のパスワードにしてください。');
    }
}

function find_tenant_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM tenants WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_tenant_by_id(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tenants WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * テナントを作成する。email 重複時は例外。
 */
function create_tenant(string $email, string $password, string $displayName, bool $isAdmin = false): string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new \InvalidArgumentException('メールアドレスの形式が正しくありません。');
    }
    assert_password_strength($password);
    if (find_tenant_by_email($email) !== null) {
        throw new \RuntimeException('このメールアドレスは既に登録されています。');
    }

    $id = generate_tenant_id();
    $stmt = db()->prepare(
        'INSERT INTO tenants (id, email, password_hash, display_name, is_admin, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $displayName !== '' ? $displayName : $email,
        $isAdmin ? 1 : 0,
        time(),
    ]);
    return $id;
}

/* ------------------------- ログイン ------------------------- */

/**
 * メール＋パスワードでログイン。成功でセッションに保存し true。
 */
function login_tenant(string $email, string $password): bool
{
    // タイミング攻撃によるアカウント列挙対策: 未知メールでも bcrypt 検証を1回行い応答時間を平準化する。
    $dummyHash = '$2y$12$iOI7xMnDX6U9v5ZKJ/SC1O4K8KEa/DBdKX6/VaaIg3PcM5nyTymFq';
    $tenant = find_tenant_by_email($email);
    if ($tenant === null) {
        password_verify($password, $dummyHash);
        return false;
    }
    if (!password_verify($password, $tenant['password_hash'])) {
        return false;
    }
    session_boot();
    session_regenerate_id(true);
    $_SESSION['tenant_id'] = $tenant['id'];
    return true;
}

function logout_tenant(): void
{
    session_boot();
    $_SESSION = [];
    session_destroy();
}

/** 現在ログイン中のテナント（未ログインなら null）。 */
function current_tenant(): ?array
{
    session_boot();
    $id = $_SESSION['tenant_id'] ?? '';
    if ($id === '') {
        return null;
    }
    return find_tenant_by_id($id);
}

/** ログイン必須。未ログインならログイン画面へリダイレクト。 */
function require_tenant(): array
{
    $tenant = current_tenant();
    if ($tenant === null) {
        header('Location: login');
        exit;
    }
    return $tenant;
}

/** プラットフォーム管理者必須。 */
function require_admin_tenant(): array
{
    $tenant = require_tenant();
    if ((int) ($tenant['is_admin'] ?? 0) !== 1) {
        audit_log('authz.admin_deny', ['tenant' => $tenant['id'], 'path' => $_SERVER['SCRIPT_NAME'] ?? '']);
        http_response_code(403);
        exit('この操作にはプラットフォーム管理者権限が必要です。');
    }
    return $tenant;
}

/* ------------------------- 招待 ------------------------- */

function generate_invite_code(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * 招待コードを発行する。
 */
function create_invite(string $createdBy, ?string $email = null, ?int $ttlDays = 14): string
{
    $code = generate_invite_code();
    $expires = $ttlDays !== null ? time() + $ttlDays * 86400 : null;
    $stmt = db()->prepare(
        'INSERT INTO invites (code, email, created_by, expires_at, created_at) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$code, $email ? strtolower(trim($email)) : null, $createdBy, $expires, time()]);
    return $code;
}

/**
 * 有効な（未使用・期限内の）招待を返す。無効なら null。
 */
function find_valid_invite(string $code): ?array
{
    $stmt = db()->prepare('SELECT * FROM invites WHERE code = ?');
    $stmt->execute([$code]);
    $invite = $stmt->fetch();
    if (!$invite) {
        return null;
    }
    if ($invite['used_by'] !== null) {
        return null;
    }
    if ($invite['expires_at'] !== null && (int) $invite['expires_at'] < time()) {
        return null;
    }
    return $invite;
}

function consume_invite(string $code, string $tenantId): void
{
    $stmt = db()->prepare('UPDATE invites SET used_by = ? WHERE code = ? AND used_by IS NULL');
    $stmt->execute([$tenantId, $code]);
}

/* ------------------- アカウント設定・パスワード ------------------- */

/** 表示名を更新する。 */
function update_tenant_display_name(string $tenantId, string $name): void
{
    $stmt = db()->prepare('UPDATE tenants SET display_name = ? WHERE id = ?');
    $stmt->execute([$name !== '' ? $name : '運営者', $tenantId]);
}

/** パスワードを更新する（強度チェックあり）。 */
function update_tenant_password(string $tenantId, string $newPassword): void
{
    assert_password_strength($newPassword);
    $stmt = db()->prepare('UPDATE tenants SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $tenantId]);
}

/* ------------------- パスワード再設定 ------------------- */

/**
 * パスワード再設定トークンを発行する。存在しないメールでも例外にせず null を返す
 * （アカウントの有無を外部に漏らさないため）。
 */
function create_password_reset(string $email, int $ttlSec = 3600): ?string
{
    $tenant = find_tenant_by_email($email);
    if ($tenant === null) {
        return null;
    }
    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO password_resets (token, tenant_id, expires_at, created_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$token, $tenant['id'], time() + $ttlSec, time()]);
    return $token;
}

/** 有効な再設定トークンに対応するレコードを返す。無効なら null。 */
function find_valid_reset(string $token): ?array
{
    $stmt = db()->prepare('SELECT * FROM password_resets WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['used'] === 1 || (int) $row['expires_at'] < time()) {
        return null;
    }
    return $row;
}

/** トークンを使ってパスワードを再設定する。成功で true。 */
function consume_password_reset(string $token, string $newPassword): bool
{
    $reset = find_valid_reset($token);
    if ($reset === null) {
        return false;
    }
    update_tenant_password($reset['tenant_id'], $newPassword);
    $stmt = db()->prepare('UPDATE password_resets SET used = 1 WHERE token = ?');
    $stmt->execute([$token]);
    return true;
}
