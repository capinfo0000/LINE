<?php

/**
 * 運用用 CLI。
 *
 * 使い方:
 *   php bin/console.php init                       … DB を作成（スキーマ初期化）
 *   php bin/console.php create-admin <email> <pw>  … 運営管理者を作成
 *   php bin/console.php make-invite <admin-email>  … 招待コードを発行して表示
 *   php bin/console.php list-operators             … 運営者アカウント一覧
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI からのみ実行できます。\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'init':
        db(); // 接続＝マイグレーション実行
        echo "DB を初期化しました。\n";
        break;

    case 'create-admin':
        $email = $argv[2] ?? '';
        $pw = $argv[3] ?? '';
        if ($email === '' || $pw === '') {
            exit("使い方: php bin/console.php create-admin <email> <password>\n");
        }
        try {
            $id = create_tenant($email, $pw, '運営管理者', true);
            echo "管理者を作成しました: {$email} (id={$id})\n";
        } catch (\Throwable $e) {
            exit('失敗: ' . $e->getMessage() . "\n");
        }
        break;

    case 'make-invite':
        $adminEmail = $argv[2] ?? '';
        $admin = $adminEmail !== '' ? find_tenant_by_email($adminEmail) : null;
        if ($admin === null || (int) $admin['is_admin'] !== 1) {
            exit("管理者のメールを指定してください（先に create-admin を実行）。\n");
        }
        $code = create_invite($admin['id']);
        $base = rtrim(env('APP_BASE_URL', 'http://localhost:8000'), '/');
        echo "招待コード: {$code}\n";
        echo "サインアップURL: {$base}/admin/signup.php?invite={$code}\n";
        break;

    case 'list-operators':
        foreach (db()->query('SELECT id, email, display_name, is_admin FROM tenants ORDER BY created_at') as $t) {
            $role = $t['is_admin'] ? '[admin]' : '';
            echo "{$t['id']}  {$t['email']}  {$t['display_name']}  {$role}\n";
        }
        break;

    case 'make-member':
        // 会員を作成し発行ID＋仮PWを表示する（Phase 2の入金Webhookが行う処理の手動版）。
        $email = $argv[2] ?? '';
        $name = $argv[3] ?? '';
        $cred = issue_member_credentials($email !== '' ? $email : null, $name !== '' ? $name : null, 'active');
        echo "会員を作成しました（active・初回PW強制変更）:\n";
        echo "  member_id : {$cred['member_id']}\n";
        echo "  ログインID : {$cred['login_id']}\n";
        echo "  仮パスワード: {$cred['temp_password']}\n";
        break;

    case 'list-members':
        foreach (db()->query('SELECT id, login_id, display_name, status, must_change_pw FROM members ORDER BY created_at') as $m) {
            $mc = $m['must_change_pw'] ? '(要PW変更)' : '';
            echo "{$m['id']}  {$m['login_id']}  {$m['status']}  {$m['display_name']}  {$mc}\n";
        }
        break;

    case 'create-slot':
        // create-slot <seminar|interview> "YYYY-MM-DD HH:MM" [capacity]  ※時刻はJST
        $kind = $argv[2] ?? '';
        $when = $argv[3] ?? '';
        $cap = (int) ($argv[4] ?? 1);
        if (!in_array($kind, ['seminar', 'interview'], true) || $when === '') {
            exit("使い方: php bin/console.php create-slot <seminar|interview> \"YYYY-MM-DD HH:MM\" [capacity]\n");
        }
        try {
            $ts = (new DateTime($when, new DateTimeZone('Asia/Tokyo')))->getTimestamp();
        } catch (\Throwable $e) {
            exit("日時の形式が不正です（例: \"2026-08-01 19:00\"）。\n");
        }
        $id = create_slot($kind, $ts, $cap > 0 ? $cap : 1);
        echo "枠を作成しました: {$id}  {$kind}  " . date('c', $ts) . "  capacity=" . ($cap > 0 ? $cap : 1) . "\n";
        break;

    case 'list-slots':
        foreach (db()->query('SELECT id, kind, start_at, capacity, booked_count, is_open FROM slots ORDER BY start_at') as $s) {
            echo "{$s['id']}  {$s['kind']}  " . date('c', (int) $s['start_at']) .
                 "  {$s['booked_count']}/{$s['capacity']}  " . ($s['is_open'] ? 'open' : 'closed') . "\n";
        }
        break;

    case 'add-openchat':
        // add-openchat <invite_url> [name]
        $url = $argv[2] ?? '';
        $name = $argv[3] ?? 'オープンチャット';
        if ($url === '') {
            exit("使い方: php bin/console.php add-openchat <invite_url> [name]\n");
        }
        $gid = 'grp_' . bin2hex(random_bytes(5));
        $stmt = db()->prepare("INSERT INTO groups (id, name, kind, invite_url, is_active, created_at) VALUES (?,?,'openchat',?,1,?)");
        $stmt->execute([$gid, $name, $url, time()]);
        echo "オープンチャットURLを登録しました: {$gid}\n";
        break;

    case 'approve-contact':
        // approve-contact <line_user_id>
        //   無料フェーズ → 決済なしで会員資格を発行して配布
        //   課金フェーズ → 決済リンクを Push
        $lu = $argv[2] ?? '';
        if ($lu === '' || find_line_contact($lu) === null) {
            exit("line_contact が見つかりません: {$lu}\n");
        }
        $r = approve_line_contact($lu);
        echo "[{$r['phase']}] {$r['message']}\n";
        if (!empty($r['member_id'])) {
            echo "  member_id: {$r['member_id']}\n";
        }
        break;

    case 'list-contacts':
        foreach (db()->query('SELECT line_user_id, onboarding_state, approved, email, member_id FROM line_contacts ORDER BY created_at') as $c) {
            $ap = $c['approved'] ? '[approved]' : '';
            echo "{$c['line_user_id']}  {$c['onboarding_state']}  {$ap}  " . ($c['email'] ?? '-') . "  " . ($c['member_id'] ?? '-') . "\n";
        }
        break;

    case 'eval-waiver':
        // 紹介特典（月額無料化）の判定を手動実行する（cron と同じ処理）。
        if (!billing_started()) {
            echo "無料フェーズのため判定対象はありません。\n";
            break;
        }
        init_stripe();
        $r = evaluate_referral_waiver();
        echo "紹介特典判定(mode={$r['mode']}): scanned={$r['scanned']} applied={$r['applied']} removed={$r['removed']} errors={$r['errors']}\n";
        break;

    default:
        echo "コマンド: init | create-admin | make-invite | list-operators | make-member | list-members\n"
           . "        create-slot | list-slots | add-openchat | approve-contact | list-contacts | eval-waiver\n";
}
