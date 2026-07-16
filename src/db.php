<?php

/**
 * SQLite データ層。
 *
 * マルチテナント化に伴い、主催者アカウント・招待・イベントを永続化する。
 * （参加者の決済データは引き続き各テナントの Stripe が正。ここには保存しない。）
 *
 * DB ファイルは Web 公開領域の外（プロジェクト直下の data/）に置く。
 */

declare(strict_types=1);

/**
 * PDO(SQLite) のシングルトン。初回アクセス時にスキーマを作成する。
 */
/** 使用する SQLite ファイルのパス（DB_PATH 指定が無ければ data/app.sqlite）。 */
function current_db_path(): string
{
    return env('DB_PATH', APP_ROOT . '/data/app.sqlite');
}

function db(): \PDO
{
    static $pdo = null;
    if ($pdo instanceof \PDO) {
        return $pdo;
    }

    $dir = APP_ROOT . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    // 保険的防御: data/ が万一公開領域に置かれても Web から DB を直接DLできないよう deny を置く。
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        );
    }
    $path = current_db_path();
    // DB_PATH に指定したディレクトリ（例: 公開フォルダ外の private/）が無ければ作成する。
    $pathDir = dirname($path);
    if ($pathDir !== '' && !is_dir($pathDir)) {
        @mkdir($pathDir, 0700, true);
    }

    $pdo = new \PDO('sqlite:' . $path, null, null, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    db_migrate($pdo);

    return $pdo;
}

/**
 * スキーマ作成（冪等）。
 */
function db_migrate(\PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS tenants (
            id                TEXT PRIMARY KEY,
            email             TEXT NOT NULL UNIQUE,
            password_hash     TEXT NOT NULL,
            display_name      TEXT NOT NULL DEFAULT '',
            stripe_account_id TEXT,                       -- Connect で紐付く acct_...（未連携なら NULL）
            is_admin           INTEGER NOT NULL DEFAULT 0, -- プラットフォーム管理者（招待を発行できる）
            plan               TEXT NOT NULL DEFAULT 'free',-- 料金プラン（登録できるイベント数の上限）
            stripe_customer_id TEXT,                        -- プラン課金用の顧客（プラットフォーム本体）
            created_at         INTEGER NOT NULL
        );
    SQL);

    // 既存DB（列が無い）への後方互換マイグレーション
    db_add_column_if_missing($pdo, 'tenants', 'plan', "TEXT NOT NULL DEFAULT 'free'");
    db_add_column_if_missing($pdo, 'tenants', 'stripe_customer_id', 'TEXT'); // プラン課金用（プラットフォーム本体の顧客）
    db_add_column_if_missing($pdo, 'tenants', 'stripe_secret_enc', 'TEXT');  // 主催者が画面登録した Stripe 秘密鍵（AES-256-GCM 暗号化）
    db_add_column_if_missing($pdo, 'tenants', 'cancel_policy', 'TEXT');      // 主催者ごとのキャンセル・返金ポリシー本文（未設定なら既定文面）

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS invites (
            code       TEXT PRIMARY KEY,
            email      TEXT,            -- 招待先を限定したい場合（任意）
            created_by TEXT,            -- 発行した管理者 tenant.id
            used_by    TEXT,            -- 使用した tenant.id（未使用なら NULL）
            expires_at INTEGER,         -- 有効期限（NULL なら無期限）
            created_at INTEGER NOT NULL
        );
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS events (
            id            TEXT PRIMARY KEY,
            tenant_id     TEXT NOT NULL,
            name          TEXT NOT NULL,
            description   TEXT NOT NULL DEFAULT '',
            date          TEXT NOT NULL DEFAULT '',
            place         TEXT NOT NULL DEFAULT '',
            amount        INTEGER NOT NULL DEFAULT 0,   -- 事前決済の単価（最小通貨単位）
            amount_onsite INTEGER NOT NULL DEFAULT 0,   -- 当日支払いの単価
            currency      TEXT NOT NULL DEFAULT 'jpy',
            capacity      INTEGER NOT NULL DEFAULT 0,
            allow_prepay  INTEGER NOT NULL DEFAULT 1,
            allow_onsite  INTEGER NOT NULL DEFAULT 0,
            created_at    INTEGER NOT NULL,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_tenant ON events(tenant_id);');

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS password_resets (
            token      TEXT PRIMARY KEY,
            tenant_id  TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            used       INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        );
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS login_attempts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,   -- メール（小文字）
            ip         TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts_id ON login_attempts(identifier, created_at);');

    // 汎用レート制限（未認証エンドポイントの濫用対策）。action 単位・identifier(IP等)単位で回数を数える。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS rate_events (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            action     TEXT NOT NULL,   -- 'signup' / 'forgot' / 'apply' など
            identifier TEXT NOT NULL,   -- 通常は送信元IP
            created_at INTEGER NOT NULL
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_events ON rate_events(action, identifier, created_at);');

    // 残席（headcount）の短時間キャッシュ。公開ページからの Stripe 全件取得の連打を防ぐ。
    // ※あくまで「表示用」。定員の最終判定（checkout）はキャッシュを使わず都度算定する。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS headcount_cache (
            event_id   TEXT PRIMARY KEY,
            headcount  INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
    SQL);
}

/**
 * 指定テーブルに列が無ければ追加する（簡易マイグレーション用）。
 */
function db_add_column_if_missing(\PDO $pdo, string $table, string $column, string $definition): void
{
    $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === $column) {
            return;
        }
    }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}
