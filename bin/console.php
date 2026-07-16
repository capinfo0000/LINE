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

    default:
        echo "コマンド: init | create-admin | make-invite | list-operators\n";
}
