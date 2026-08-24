<?php

/**
 * 会員管理：一覧・検索。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = (string) ($_GET['msg'] ?? '');
$msgType = 'ok';

// 一覧からの会員削除（完全削除・元に戻せない）。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_member') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $ok = admin_delete_member((string) ($_POST['id'] ?? ''));
    header('Location: members.php?msg=' . rawurlencode($ok ? '会員を削除しました。' : '会員が見つかりませんでした。'));
    exit;
}

$keyword = (string) ($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$members = admin_search_members($keyword, $status);
$token = csrf_token();

$pageTitle = '会員管理';
$pageSub = count($members) . ' 件';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<form method="get" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
    <div style="flex:1;min-width:180px;">
        <label>キーワード（ID・メール・名前）</label>
        <input type="text" name="q" value="<?= e($keyword) ?>">
    </div>
    <div>
        <label>ステータス</label>
        <select name="status">
            <?php foreach (['' => 'すべて', 'active' => '有効', 'pending_payment' => '未入金', 'suspended' => '停止', 'cancelled' => '退会'] as $v => $l): ?>
                <option value="<?= e($v) ?>"<?= $status === $v ? ' selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div><button type="submit" class="btn">検索</button></div>
</form>

<div class="card">
    <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
        <tr style="text-align:left;border-bottom:1px solid var(--border);">
            <th style="padding:6px;">ログインID</th><th>名前</th><th>ステータス</th><th>メール</th><th>登録日</th><th></th>
        </tr>
        <?php foreach ($members as $m): ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:6px;"><code><?= e($m['login_id']) ?></code></td>
                <td><?= e($m['name_text'] ?? $m['display_name'] ?? '') ?></td>
                <td><?= e($m['status']) ?><?= (int) $m['must_change_pw'] === 1 ? ' <span class="muted">(要PW変更)</span>' : '' ?></td>
                <td><?= e($m['email'] ?? '-') ?></td>
                <td><?= e(date('Y-m-d', (int) $m['created_at'])) ?></td>
                <td style="white-space:nowrap;">
                    <a href="member_detail.php?id=<?= e($m['id']) ?>">詳細</a>
                    <form method="post" style="display:inline;margin-left:8px;">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                        <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                        <input type="hidden" name="action" value="delete_member">
                        <button style="border:none;background:none;cursor:pointer;color:var(--dng);font-size:.82rem;padding:0;"
                                data-confirm="会員「<?= e($m['name_text'] ?? $m['login_id']) ?>」を完全に削除します。元に戻せません。よろしいですか？">削除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($members === []): ?><p class="muted">該当する会員がいません。</p><?php endif; ?>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
