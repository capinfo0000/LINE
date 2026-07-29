<?php

/**
 * 初期設定（外部連携キー）— 管理者専用。
 * Stripe / LINE / Zoom などの鍵を入力して .env にマージ更新する。
 * セットアップ完了後は「このページを削除」で自身のファイルを消せる（再表示不可になる）。
 *
 * ※ 秘密情報を扱うため is_admin のみ。値はフォームに再表示せず、設定済み/未設定のみ表示する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
if ((int) ($tenant['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    exit('この操作は管理者のみ可能です。');
}

/** .env 用に値を安全な1行へ整形（改行除去・ダブルクオート囲み）。 */
function envset_line(string $key, string $value): string
{
    $value = str_replace(["\r", "\n"], '', $value);
    $value = str_replace('"', '\"', $value);
    return $key . '="' . $value . '"';
}

/** 既存の .env を保ったまま、指定キーだけ更新（無ければ追記）する。 */
function envset_apply(string $envPath, array $updates): bool
{
    $lines = is_file($envPath) ? file($envPath, FILE_IGNORE_NEW_LINES) : [];
    $seen = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=/', $line, $m) && array_key_exists($m[1], $updates)) {
            $lines[$i] = envset_line($m[1], (string) $updates[$m[1]]);
            $seen[$m[1]] = true;
        }
    }
    foreach ($updates as $k => $v) {
        if (empty($seen[$k])) {
            $lines[] = envset_line($k, (string) $v);
        }
    }
    return @file_put_contents($envPath, implode("\n", $lines) . "\n", LOCK_EX) !== false;
}

// 入力フィールド定義: [key, ラベル, 秘密か, 補足]
$fields = [
    ['STRIPE_SECRET_KEY',        'Stripe シークレットキー',            true,  'sk_live_… / テストは sk_test_…'],
    ['STRIPE_PRICE_ID',          'Stripe 月額Price ID',                false, 'price_…（継続Price）'],
    ['STRIPE_WEBHOOK_SECRET',    'Stripe Webhook 署名シークレット',    true,  'whsec_…'],
    ['MONTHLY_FEE_AMOUNT',       '月額の表示金額（円・任意）',          false, '例: 1000（0で非表示）'],
    ['LINE_CHANNEL_SECRET',      'LINE チャネルシークレット',          true,  'Messaging API'],
    ['LINE_CHANNEL_ACCESS_TOKEN','LINE チャネルアクセストークン',      true,  'Messaging API'],
    ['ZOOM_ACCOUNT_ID',          'Zoom Account ID',                    false, 'Server-to-Server OAuth'],
    ['ZOOM_CLIENT_ID',           'Zoom Client ID',                     false, ''],
    ['ZOOM_CLIENT_SECRET',       'Zoom Client Secret',                 true,  ''],
    ['APP_BASE_URL',             'アプリの公開URL',                    false, 'https://ドメイン'],
];

$envPath = APP_ROOT . '/.env';
$msg = '';
$msgType = 'ok';
$token = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $updates = [];
        foreach ($fields as [$key, , , ]) {
            $v = trim((string) ($_POST[$key] ?? ''));
            if ($v !== '') { // 空欄は「現状維持」
                $updates[$key] = $v;
            }
        }
        if ($updates === []) {
            $msg = '入力がありません（空欄は変更されません）。';
            $msgType = 'ng';
        } elseif (envset_apply($envPath, $updates)) {
            audit_log('settings.env_updated', ['keys' => implode(',', array_keys($updates))]);
            $msg = count($updates) . ' 件を .env に保存しました（次のリクエストから反映）。';
        } else {
            $msg = '.env の書き込みに失敗しました（プロジェクト直下の書き込み権限を確認してください）。';
            $msgType = 'ng';
        }
    } elseif ($action === 'selfdestruct') {
        @unlink(__FILE__);
        audit_log('settings.env_page_deleted', []);
        header('Location: dashboard.php?msg=' . rawurlencode('初期設定ページを削除しました。') . '&type=ok');
        exit;
    }
}

$pageTitle = '初期設定（外部連携キー）';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
    <p class="muted" style="margin-top:0;">各キーを入力して保存すると <code>.env</code> に反映されます。<strong>空欄の項目は変更されません</strong>（現在値を保持）。値は安全のため画面に再表示しません。</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="save">
        <?php foreach ($fields as [$key, $label, $secret, $hint]):
            $cur = (string) (env($key) ?? '');
            $isSet = $cur !== '';
            ?>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:600;"><?= e($label) ?>
                    <span class="badge" style="background:<?= $isSet ? 'var(--ok-bg)' : 'var(--dng-bg)' ?>;color:<?= $isSet ? 'var(--ok-fg)' : 'var(--dng-fg)' ?>;font-size:.72rem;"><?= $isSet ? '設定済み' : '未設定' ?></span>
                </label>
                <?php if ($isSet && !$secret): ?>
                    <div class="muted" style="font-size:.82rem;">現在値: <code><?= e($cur) ?></code></div>
                <?php endif; ?>
                <input type="<?= $secret ? 'password' : 'text' ?>" name="<?= e($key) ?>" autocomplete="off"
                       placeholder="<?= e($isSet ? '変更する場合のみ入力' : ($hint !== '' ? $hint : '')) ?>"
                       style="width:100%;max-width:520px;">
                <?php if ($hint !== '' && !$isSet): ?><div class="muted" style="font-size:.78rem;"><?= e($hint) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <p><button type="submit" class="btn">保存する</button></p>
    </form>
</div>

<div class="card">
    <div class="card__title">セットアップ完了後</div>
    <p class="muted">初期設定が終わったら、このページ自体を削除して非表示にできます（再度必要になったら再アップロードすればOK）。</p>
    <form method="post" data-confirm="この初期設定ページ(settings_env.php)を削除します。以後この画面は開けなくなります。よろしいですか？">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="selfdestruct">
        <button type="submit" class="btn btn--ghost" style="color:var(--dng);border-color:var(--dng);">このページを削除する</button>
    </form>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
