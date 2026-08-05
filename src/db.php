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

    // ------- 会員ドメイン（Phase 1: 認証。プロフィール/マッチング等は後続で追加） -------

    // 会員アカウント。login_id（発行ID）＋ password_hash で会員サイトにログインする。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS members (
            id                 TEXT PRIMARY KEY,
            login_id           TEXT NOT NULL UNIQUE,          -- 発行ログインID（例 el8f3k9q2m）
            password_hash      TEXT NOT NULL,
            must_change_pw     INTEGER NOT NULL DEFAULT 1,    -- 初回ログイン時にPW強制変更
            display_name       TEXT NOT NULL DEFAULT '',
            email              TEXT,                          -- PW再発行・連絡用（面談〜決済で取得）
            line_user_id       TEXT,                          -- 公式LINE Bot 紐付け（Phase 3）
            status             TEXT NOT NULL DEFAULT 'active',-- lead/pending_payment/active/suspended/cancelled
            approval_state     TEXT NOT NULL DEFAULT 'none',  -- none/approved（加入承認・Phase 3）
            stripe_customer_id TEXT,                          -- 入会金決済の顧客（Phase 2）
            joined_at          INTEGER,                       -- 会員化（入金）日時
            created_at         INTEGER NOT NULL
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_members_email ON members(email);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_members_line ON members(line_user_id);');

    // 会員のパスワード再設定トークン（運営者用 password_resets とは分離）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS member_password_resets (
            token      TEXT PRIMARY KEY,
            member_id  TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            used       INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        );
    SQL);

    // ------- 入会金決済（Phase 2） -------

    // 入会金の決済記録。stripe_checkout_session_id を一意にして二重プロビジョニングを防ぐ。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS payments (
            id                         TEXT PRIMARY KEY,
            member_id                  TEXT,                    -- プロビジョニング後に紐付く会員
            stripe_checkout_session_id TEXT NOT NULL UNIQUE,    -- 冪等キー（1セッション=1決済）
            stripe_payment_intent_id   TEXT,
            stripe_customer_id         TEXT,
            email                      TEXT,
            amount                     INTEGER NOT NULL DEFAULT 0,
            currency                   TEXT NOT NULL DEFAULT 'jpy',
            status                     TEXT NOT NULL DEFAULT 'processing', -- processing/paid
            created_at                 INTEGER NOT NULL,
            paid_at                    INTEGER
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payments_member ON payments(member_id);');

    // Stripe Webhook イベントの冪等記録（同一 event.id の二重処理を防ぐ）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS stripe_events (
            event_id     TEXT PRIMARY KEY,
            type         TEXT,
            processed_at INTEGER NOT NULL
        );
    SQL);

    // ------- 公式LINE Bot オンボーディング・予約（Phase 3） -------

    // Bot と友だちになった相手のファネル状態。会員化後は member_id が紐付く。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS line_contacts (
            line_user_id    TEXT PRIMARY KEY,
            member_id       TEXT,
            display_name    TEXT,
            onboarding_state TEXT NOT NULL DEFAULT 'added',
              -- added/booked_seminar/seminar_done/booked_interview/interview_done/approved/payment_sent/paid
            approved         INTEGER NOT NULL DEFAULT 0,  -- 運営の加入承認（決済リンク送信の任意ゲート）
            email            TEXT,                        -- 面談〜決済で取得
            credentials_sent INTEGER NOT NULL DEFAULT 0,  -- ID/PW配布の冪等ガード
            created_at       INTEGER NOT NULL,
            updated_at       INTEGER NOT NULL
        );
    SQL);

    // 予約枠（説明会 / 個別面談）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS slots (
            id              TEXT PRIMARY KEY,
            kind            TEXT NOT NULL,               -- seminar / interview
            start_at        INTEGER NOT NULL,
            capacity        INTEGER NOT NULL DEFAULT 1,
            booked_count    INTEGER NOT NULL DEFAULT 0,
            zoom_meeting_id TEXT,
            zoom_url        TEXT,
            is_open         INTEGER NOT NULL DEFAULT 1,
            created_at      INTEGER NOT NULL
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_slots_kind_open ON slots(kind, is_open, start_at);');

    // 予約。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS bookings (
            id           TEXT PRIMARY KEY,
            kind         TEXT NOT NULL,                  -- seminar / interview
            line_user_id TEXT,
            member_id    TEXT,
            slot_id      TEXT NOT NULL,
            status       TEXT NOT NULL DEFAULT 'booked', -- booked/done/cancelled/noshow
            zoom_url     TEXT,
            remind_sent  INTEGER NOT NULL DEFAULT 0,
            created_at   INTEGER NOT NULL,
            FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bookings_slot ON bookings(slot_id);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bookings_line ON bookings(line_user_id);');

    // 交流グループ（オープンチャット）。招待URLは運営が手動登録し、Botが入金後に配信する。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS groups (
            id         TEXT PRIMARY KEY,
            name       TEXT NOT NULL DEFAULT '',
            kind       TEXT NOT NULL DEFAULT 'openchat',
            invite_url TEXT,
            is_active  INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL
        );
    SQL);

    // LINE 送受信の記録（通数コストの把握。push は課金、reply は無料）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS line_messages (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            line_user_id TEXT,
            direction    TEXT NOT NULL,      -- in / out
            channel      TEXT,               -- reply / push
            type         TEXT,
            billable     INTEGER NOT NULL DEFAULT 0,
            created_at   INTEGER NOT NULL
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_line_messages_created ON line_messages(created_at);');

    // ------- プロフィール・タグ・希望条件（Phase 4） -------

    // プロフィール（自由記述・顔写真・表示制御）。member_id を主キーに 1:1。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS profiles (
            member_id        TEXT PRIMARY KEY,
            name_text        TEXT NOT NULL DEFAULT '',   -- 表示名（自由記述）
            age_text         TEXT NOT NULL DEFAULT '',   -- 年齢（自由記述）
            company_title    TEXT NOT NULL DEFAULT '',   -- 会社名／屋号・肩書き
            headline         TEXT NOT NULL DEFAULT '',   -- ひとことPR
            bio              TEXT NOT NULL DEFAULT '',   -- 自己紹介
            photo_path       TEXT,                       -- 公開領域外の相対保存パス
            photo_status     TEXT NOT NULL DEFAULT 'none',-- none/pending/approved/rejected
            visibility_flags TEXT NOT NULL DEFAULT '{}', -- JSON（ディレクトリ掲載/リンク表示など）
            updated_at       INTEGER,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        );
    SQL);

    // リンク（LINE追加URL＋任意リンク）。1会員に複数。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS member_links (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id  TEXT NOT NULL,
            kind       TEXT NOT NULL DEFAULT 'other', -- line_add / other
            label      TEXT NOT NULL DEFAULT '',
            url        TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_member_links_member ON member_links(member_id);');

    // タグのカテゴリとタグ（運営がマスタを追加可能）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS tag_categories (
            key   TEXT PRIMARY KEY,   -- area / job / purpose / offer
            label TEXT NOT NULL,
            sort  INTEGER NOT NULL DEFAULT 0
        );
    SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS tags (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            category_key TEXT NOT NULL,
            label        TEXT NOT NULL,
            sort         INTEGER NOT NULL DEFAULT 0,
            is_active    INTEGER NOT NULL DEFAULT 1,
            UNIQUE (category_key, label)
        );
    SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS member_tags (
            member_id TEXT NOT NULL,
            tag_id    INTEGER NOT NULL,
            PRIMARY KEY (member_id, tag_id),
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_member_tags_tag ON member_tags(tag_id);');

    // 求める条件（相手に求める。未指定＝問わない）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS match_preferences (
            member_id    TEXT PRIMARY KEY,
            seek_area    TEXT NOT NULL DEFAULT '[]',   -- JSON: tag_id 配列（area）
            seek_job     TEXT NOT NULL DEFAULT '[]',   -- JSON: tag_id 配列（job）
            seek_purpose TEXT NOT NULL DEFAULT '[]',   -- JSON: tag_id 配列（purpose）
            updated_at   INTEGER,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        );
    SQL);

    // 双方向マッチのおすすめ結果（Phase 6・週次バッチで再構築）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS recommendations (
            id                    INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id              TEXT NOT NULL,
            member_id             TEXT NOT NULL,
            recommended_member_id TEXT NOT NULL,
            score                 INTEGER NOT NULL DEFAULT 0,
            reason_json           TEXT NOT NULL DEFAULT '[]',
            created_at            INTEGER NOT NULL,
            UNIQUE (member_id, recommended_member_id, batch_id)
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reco_member ON recommendations(member_id, score);');

    // ポイント台帳（増減を1行ずつ記録。残高は合計で求める）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS point_ledger (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id     TEXT NOT NULL,
            delta         INTEGER NOT NULL,
            reason        TEXT NOT NULL,
            ref_member_id TEXT,
            note          TEXT NOT NULL DEFAULT '',
            created_at    INTEGER NOT NULL,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ledger_member ON point_ledger(member_id, id);');

    // 紹介（1入会者につき紹介者は1人）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS referrals (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            referrer_id TEXT NOT NULL,
            joiner_id   TEXT NOT NULL UNIQUE,
            created_at  INTEGER NOT NULL,
            FOREIGN KEY (referrer_id) REFERENCES members(id) ON DELETE CASCADE,
            FOREIGN KEY (joiner_id)   REFERENCES members(id) ON DELETE CASCADE
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_referrals_referrer ON referrals(referrer_id);');

    // 会員間の評価(praise)・通報(report)。同一ペア・同一種別は1回のみ。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS member_evaluations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            rater_id   TEXT NOT NULL,
            target_id  TEXT NOT NULL,
            kind       TEXT NOT NULL,          -- praise / report
            note       TEXT NOT NULL DEFAULT '',
            handled    INTEGER NOT NULL DEFAULT 0, -- report のレビュー済みフラグ
            created_at INTEGER NOT NULL,
            UNIQUE (rater_id, target_id, kind),
            FOREIGN KEY (rater_id)  REFERENCES members(id) ON DELETE CASCADE,
            FOREIGN KEY (target_id) REFERENCES members(id) ON DELETE CASCADE
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_eval_target ON member_evaluations(target_id, kind);');

    // サブスク（月額会費）用の会員カラム。
    db_add_column_if_missing($pdo, 'members', 'stripe_subscription_id', 'TEXT');
    db_add_column_if_missing($pdo, 'members', 'subscription_status', "TEXT NOT NULL DEFAULT ''"); // active/past_due/canceled

    // 紹介者への月次ポイント配布の冪等記録（請求1件につき1回だけ付与）。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS referral_payouts (
            invoice_id  TEXT PRIMARY KEY,
            referrer_id TEXT NOT NULL,
            joiner_id   TEXT NOT NULL,
            points      INTEGER NOT NULL,
            created_at  INTEGER NOT NULL
        );
    SQL);

    // 未発行の枠を告知した相手を保留し、Zoom URL発行時に自動送信するためのキュー。
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS slot_url_pending (
            slot_id      TEXT NOT NULL,
            line_user_id TEXT NOT NULL,
            created_at   INTEGER NOT NULL,
            PRIMARY KEY (slot_id, line_user_id)
        );
    SQL);

    // 写真承認フロー廃止に伴う正規化（冪等）：承認待ちのまま残っている写真を公開状態にする。
    // アップロードは即 'approved' になったため、既存の 'pending' のみを一度だけ引き上げる。
    $pdo->exec("UPDATE profiles SET photo_status = 'approved' WHERE photo_status = 'pending'");

    // タグマスタの初期投入（未投入時のみ）。
    seed_tag_master($pdo);
}

/**
 * タグのカテゴリ・初期タグを投入する（冪等・未投入時のみ）。運営はコンソール/管理画面で追加可能。
 */
function seed_tag_master(\PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM tag_categories')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $cats = [
        ['area', '場所', 1],
        ['job', '仕事ジャンル', 2],
        ['purpose', '目的（求めること）', 3],
        ['offer', '提供できること', 4],
    ];
    $insCat = $pdo->prepare('INSERT OR IGNORE INTO tag_categories (key, label, sort) VALUES (?,?,?)');
    foreach ($cats as $c) {
        $insCat->execute($c);
    }

    $prefectures = [
        '北海道', '青森', '岩手', '宮城', '秋田', '山形', '福島',
        '茨城', '栃木', '群馬', '埼玉', '千葉', '東京', '神奈川',
        '新潟', '富山', '石川', '福井', '山梨', '長野', '岐阜', '静岡', '愛知',
        '三重', '滋賀', '京都', '大阪', '兵庫', '奈良', '和歌山',
        '鳥取', '島根', '岡山', '広島', '山口',
        '徳島', '香川', '愛媛', '高知',
        '福岡', '佐賀', '長崎', '熊本', '大分', '宮崎', '鹿児島', '沖縄',
    ];
    $jobs = ['IT・Web', '製造', '建設', '飲食', '小売', '医療・福祉', '士業', '金融', '不動産', '教育', 'クリエイティブ', '広告・マーケ', 'コンサル', 'その他'];
    // purpose と offer は同じ価値ボキャブラリを共有（求めること↔提供できること の重なりでマッチ）
    $values = ['協業', '顧客紹介', '販路開拓', '仕入・調達', '資金・出資', '採用・人材', '技術・開発', 'ノウハウ提供', '情報交換', '仲間づくり'];

    $insTag = $pdo->prepare('INSERT OR IGNORE INTO tags (category_key, label, sort) VALUES (?,?,?)');
    $sort = 0;
    foreach ($prefectures as $p) {
        $insTag->execute(['area', $p, $sort++]);
    }
    $sort = 0;
    foreach ($jobs as $j) {
        $insTag->execute(['job', $j, $sort++]);
    }
    $sort = 0;
    foreach ($values as $v) {
        $insTag->execute(['purpose', $v, $sort]);
        $insTag->execute(['offer', $v, $sort]);
        $sort++;
    }
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
