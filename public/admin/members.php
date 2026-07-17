<?php

/**
 * 会員管理：一覧・検索。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$keyword = (string) ($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$members = admin_search_members($keyword, $status);

$pageTitle = '会員管理';
$pageSub = count($members) . ' 件';
require __DIR__ . '/_app_header.php';
?>
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
                <td><a href="member_detail.php?id=<?= e($m['id']) ?>">詳細</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($members === []): ?><p class="muted">該当する会員がいません。</p><?php endif; ?>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
