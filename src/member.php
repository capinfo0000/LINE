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
 * プロフィール共有用の短いコード。
 *
 * 会員の内部ID（mem_xxxxxxxxxxxx）をURLに出したくないので、URL向けの短いコードを別に持つ。
 * ログインIDや紹介コードとは別物で、これが漏れても入会や乗っ取りには使えない
 * （見られるのはプロフィール詳細だけで、閲覧側のログインと公開設定は従来どおり効く）。
 */
function public_code_exists(string $code): bool
{
    $stmt = db()->prepare('SELECT 1 FROM members WHERE public_code = ? LIMIT 1');
    $stmt->execute([$code]);
    return (bool) $stmt->fetchColumn();
}

/** 重複しない共有コードを生成する（紛らわしい文字を避けた小文字英数字8桁）。 */
function generate_member_public_code(): string
{
    do {
        $code = random_safe_string(8);
    } while (public_code_exists($code));
    return $code;
}

/**
 * 会員の共有コードを返す（未発行ならその場で発行して保存）。
 * 渡す配列の会員IDのキーは id でも member_id でもよい（検索結果の行をそのまま渡せるように）。
 */
function member_public_code(array $member): string
{
    $code = trim((string) ($member['public_code'] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $id = (string) ($member['id'] ?? $member['member_id'] ?? '');
    if ($id === '') {
        return '';
    }
    $code = generate_member_public_code();
    db()->prepare('UPDATE members SET public_code = ? WHERE id = ?')->execute([$code, $id]);
    return $code;
}

/** 共有コードから会員を探す。 */
function find_member_by_public_code(string $code): ?array
{
    $code = trim($code);
    if ($code === '' || !preg_match('/^[a-z0-9]{4,32}$/', $code)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM members WHERE public_code = ? LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * サイト内で使うプロフィールのパス（例 /u/k3f9qa2m）。
 * 共有コードがどうしても引けない場合だけ、従来の内部ID付きURLに落とす。
 */
function member_public_path(array $member): string
{
    $code = member_public_code($member);
    if ($code === '') {
        $id = (string) ($member['id'] ?? $member['member_id'] ?? '');
        return '/member/member_view.php?id=' . rawurlencode($id);
    }
    return '/u/' . $code;
}

/** 人に渡す用の絶対URL（例 https://example.com/u/k3f9qa2m）。コピー・共有はこちらを使う。 */
function member_public_url(array $member): string
{
    return rtrim(base_url(), '/') . member_public_path($member);
}

/**
 * 他会員の画像の配信URL。
 *
 * 内部ID（mem_...）ではなく共有コードで指す。プロフィールのURLだけ短くしても、
 * ページの中の画像URLに内部IDが残っていては隠したことにならないため。
 * 配信側（photo.php）の認可はどちらの指定でも同じ。
 *
 * @param array $row public_code か member_id/id を含む行
 */
function member_photo_url(array $row, string $kind = 'photo', int $version = 0): string
{
    $code = trim((string) ($row['public_code'] ?? ''));
    if ($code === '') {
        // 共有コードが未発行の行（旧データ）はその場で発行して使う。
        $id = (string) ($row['member_id'] ?? $row['id'] ?? '');
        if ($id === '') {
            return '/member/photo.php';
        }
        $code = member_public_code(['id' => $id, 'public_code' => '']);
    }
    $q = 'c=' . rawurlencode($code);
    if ($kind !== 'photo') {
        $q .= '&kind=' . rawurlencode($kind);
    }
    if ($version > 0) {
        $q .= '&v=' . $version;
    }
    return '/member/photo.php?' . $q;
}

/** 紹介コードが既に使われているか。 */
function referral_code_exists(string $code): bool
{
    $stmt = db()->prepare('SELECT 1 FROM members WHERE referral_code = ? LIMIT 1');
    $stmt->execute([$code]);
    return (bool) $stmt->fetchColumn();
}

/**
 * 重複しない紹介専用コード（例 8F3KQ9MN）を生成する。
 * ログインIDとは別物。紛らわしい文字を避けた大文字英数字8桁。
 */
function generate_referral_code(): string
{
    do {
        $code = random_safe_string(8, 'ABCDEFGHJKMNPQRSTUVWXYZ23456789');
    } while (referral_code_exists($code));
    return $code;
}

/** 会員の紹介コードを返す（未発行なら発行して保存）。 */
function member_referral_code(array $member): string
{
    $code = (string) ($member['referral_code'] ?? '');
    if ($code !== '') {
        return $code;
    }
    $code = generate_referral_code();
    db()->prepare('UPDATE members SET referral_code = ? WHERE id = ?')->execute([$code, $member['id']]);
    return $code;
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
    $referralCode = generate_referral_code();
    $email = ($email !== null && trim($email) !== '') ? strtolower(trim($email)) : null;

    $publicCode = generate_member_public_code();

    $stmt = db()->prepare(
        'INSERT INTO members (id, login_id, password_hash, must_change_pw, display_name, email, line_user_id, status, referral_code, public_code, joined_at, created_at)
         VALUES (:id, :login_id, :hash, 1, :name, :email, :line, :status, :ref, :code, :joined, :created)'
    );
    $stmt->execute([
        ':id'       => $memberId,
        ':login_id' => $loginId,
        ':hash'     => password_hash($tempPassword, PASSWORD_DEFAULT),
        ':name'     => $displayName !== null ? mb_substr(trim($displayName), 0, 100) : '',
        ':email'    => $email,
        ':line'     => $lineUserId,
        ':status'   => $status,
        ':ref'      => $referralCode,
        ':code'     => $publicCode,
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

/**
 * ログインIDを変更する。半角英数字と _ . - の4〜20文字。小文字で保存し重複を禁止。
 *
 * @return array{ok:bool, message:string}
 */
function change_member_login_id(string $memberId, string $newId): array
{
    $newId = strtolower(trim($newId));
    if ($newId === '') {
        return ['ok' => false, 'message' => '新しいログインIDを入力してください。'];
    }
    if (!preg_match('/^[a-z0-9_.\-]{4,20}$/', $newId)) {
        return ['ok' => false, 'message' => 'ログインIDは半角英数字と _ . - の4〜20文字で入力してください。'];
    }
    $current = find_member_by_id($memberId);
    if ($current === null) {
        return ['ok' => false, 'message' => '会員が見つかりません。'];
    }
    if ((string) $current['login_id'] === $newId) {
        return ['ok' => false, 'message' => '現在のログインIDと同じです。'];
    }
    $other = find_member_by_login_id($newId);
    if ($other !== null && (string) $other['id'] !== $memberId) {
        return ['ok' => false, 'message' => 'このログインIDは既に使われています。別のIDをお試しください。'];
    }
    $stmt = db()->prepare('UPDATE members SET login_id = ? WHERE id = ?');
    $stmt->execute([$newId, $memberId]);
    audit_log('member.login_id_change', ['member' => $memberId]);
    return ['ok' => true, 'message' => 'ログインIDを変更しました。次回から新しいIDでログインしてください。'];
}

/** 公式LINEへの自己紹介が送信済みか。 */
function member_intro_submitted(array $member): bool
{
    return (int) ($member['intro_submitted_at'] ?? 0) > 0;
}

/** 自己紹介の送信を記録する（冪等：既に記録済みなら何もしない）。 */
function mark_intro_submitted(string $memberId): void
{
    $stmt = db()->prepare('UPDATE members SET intro_submitted_at = ? WHERE id = ? AND (intro_submitted_at IS NULL OR intro_submitted_at = 0)');
    $stmt->execute([time(), $memberId]);
}

/**
 * 自己紹介ロックを一括で免除する（ロックをOFFにしたときに呼ぶ）。
 * 対象：LINE連携あり・自己紹介が未送信・まだ免除されていない会員。
 * 「実際に送った人数」を汚さないため intro_submitted_at は触らず、免除フラグだけを立てる。
 *
 * @return int 免除した人数
 */
function intro_gate_exempt_all(): int
{
    $stmt = db()->prepare(
        "UPDATE members SET intro_gate_exempt = 1
          WHERE line_user_id IS NOT NULL AND line_user_id <> ''
            AND (intro_submitted_at IS NULL OR intro_submitted_at = 0)
            AND intro_gate_exempt = 0"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

/** 個別の免除を取り消す（管理画面で1名だけロックし直すとき）。 */
function intro_gate_unexempt(string $memberId): void
{
    db()->prepare('UPDATE members SET intro_gate_exempt = 0 WHERE id = ?')->execute([$memberId]);
}

/** この会員は自己紹介ロックを免除されているか。 */
function member_intro_exempt(array $member): bool
{
    return (int) ($member['intro_gate_exempt'] ?? 0) === 1;
}

/** 自己紹介ロック（公式LINEに送るまで さがすを見せない）が有効か。既定ON。 */
function intro_gate_enabled(): bool
{
    return app_setting_get('intro_gate', '1') !== '0';
}

/**
 * この会員は「さがす」閲覧前に自己紹介の送信が必要か。
 * ロックONかつ、LINE連携あり（公式LINEに送れる）かつ、免除でなく、未送信のとき true。
 */
function member_needs_intro(array $member): bool
{
    if (!intro_gate_enabled()) {
        return false;
    }
    if ((string) ($member['line_user_id'] ?? '') === '') {
        return false; // LINE未連携（管理発行・サンプル等）はロック対象外
    }
    if (member_intro_exempt($member)) {
        return false; // ロックOFF期間中に在籍していた会員は免除（ON に戻しても再ロックしない）
    }
    return !member_intro_submitted($member);
}

/** LINEのuserIdから会員を探す（連絡先の紐付けが無い場合のフォールバック用）。 */
function find_member_by_line_user_id(string $lineUserId): ?array
{
    if ($lineUserId === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM members WHERE line_user_id = ? LIMIT 1');
    $stmt->execute([$lineUserId]);
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
    // キーを消すだけでは、共有端末でセッションID（とCSRFトークン）が生き残る。
    // 運営側の logout_tenant() と同じく、セッションごと破棄する。
    $_SESSION = [];
    session_destroy();
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
function require_member(bool $allowDuringPwChange = false, bool $allowUnsubscribed = false): array
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
    // 未サブスクでもここでは止めない。塞ぐのは「さがす」関連だけで、
    // マイページ・プロフィール編集・ポイント・支払いは引き続き使えるようにする
    // （自分の情報に触れないと、支払う判断もできず退会にしかつながらないため）。
    // $allowUnsubscribed は呼び出し側の互換のために残している。
    return $member;
}

/**
 * この会員は「さがす」関連（会員検索・おすすめ・他会員のプロフィールと画像）が
 * 課金の都合でロックされているか。
 */
function member_search_locked(array $member): bool
{
    return function_exists('member_requires_subscription') && member_requires_subscription($member);
}

/**
 * ログイン後に戻したい場所を覚えておく（共有URLを開いた人がログイン後に
 * ダッシュボードへ飛ばされて、見に来たプロフィールを見失わないようにする）。
 *
 * 保存先はセッションで、URLのパラメータにはしない（他所へ飛ばす踏み台に使えないようにするため）。
 * 受け付けるのは「/」1個で始まる自サイト内のパスだけ。「//」始まりは外部サイトになるので拒否する。
 */
function set_login_return_path(string $path): void
{
    if ($path === '' || strncmp($path, '/', 1) !== 0 || strncmp($path, '//', 2) === 0) {
        return;
    }
    if (!preg_match('#^/[A-Za-z0-9._~/?=&%+-]{0,300}$#', $path)) {
        return;
    }
    session_boot();
    $_SESSION['member_return_to'] = $path;
}

/** 覚えておいた戻り先を取り出して消す。無ければ空文字。 */
function take_login_return_path(): string
{
    session_boot();
    $path = (string) ($_SESSION['member_return_to'] ?? '');
    unset($_SESSION['member_return_to']);
    return $path;
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
