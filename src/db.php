<?php

/**
 * SQLite データ層（単一運営）。
 *
 * 運営者アカウント（管理コンソール認証）・招待・認証補助テーブルを永続化する。
 * 会員／プロフィール／マッチング等のドメインは後続フェーズで追加する（同じ冪等パターン）。
 *
 * ※ テーブル名 `tenants` は event 基盤からの流用で、本アプリでは「運営者アカウント」を表す
 *    （単一運営でも複数スタッフを持てる）。マルチテナント固有の列（Stripe接続/プラン等）は撤去済み。
 *
 * DB ファイルは Web 公開領域の外（プロジェクト直下の data/）に置く。
 */

declare(strict_types=1);

/** 使用する SQLite ファイルのパス（DB_PATH 指定が無ければ data/app.sqlite）。 */
function current_db_path(): string
{
    return env('DB_PATH', APP_ROOT . '/data/app.sqlite');
}

/**
 * PDO(SQLite) のシングルトン。初回アクセス時にスキーマを作成する。
 */
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
    // 運営者アカウント（管理コンソール認証）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS tenants (
            id            TEXT PRIMARY KEY,
            email         TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            display_name  TEXT NOT NULL DEFAULT '',
            is_admin      INTEGER NOT NULL DEFAULT 0,  -- 招待を発行できる管理者
            created_at    INTEGER NOT NULL
        );
    SQL);

    // 運営者アカウントの招待（招待制サインアップ）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS invites (
            code       TEXT PRIMARY KEY,
            email      TEXT,            -- 招待先を限定したい場合（任意）
            created_by TEXT,            -- 発行した運営者 tenant.id
            used_by    TEXT,            -- 使用した tenant.id（未使用なら NULL）
            expires_at INTEGER,         -- 有効期限（NULL なら無期限）
            created_at INTEGER NOT NULL
        );
    SQL);

    // パスワード再設定トークン。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS password_resets (
            token      TEXT PRIMARY KEY,
            tenant_id  TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            used       INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        );
    SQL);

    // ログイン試行の記録（総当たり対策）。
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
            action     TEXT NOT NULL,   -- 'signup' / 'forgot' / 'login' など
            identifier TEXT NOT NULL,   -- 通常は送信元IP
            created_at INTEGER NOT NULL
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_events ON rate_events(action, identifier, created_at);');
}

/**
 * 指定テーブルに列が無ければ追加する（簡易マイグレーション用）。後続フェーズで使用。
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
