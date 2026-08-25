<?php

/**
 * 会員管理：一覧・検索。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = (string) ($_GET['msg'] ?? '');
$msgType = ((string) ($_GET['type'] ?? 'ok')) === 'ng' ? 'ng' : 'ok';

// 一覧からの会員削除（完全削除・元に戻せない）。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_member') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $ok = admin_delete_member((string) ($_POST['id'] ?? ''));
    header('Location: members.php?msg=' . rawurlencode($ok ? '会員を削除しました。' : '会員が見つかりませんでした。'));
    exit;
}

// 一括付与（ポイント・プラン・称号）。まとめて効くので管理者のみ。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'bulk_grant') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $back = 'members.php?' . http_build_query(['q' => (string) ($_POST['q'] ?? ''), 'status' => (string) ($_POST['status'] ?? '')]);
    if ((int) ($tenant['is_admin'] ?? 0) !== 1) {
        header('Location: ' . $back . '&msg=' . rawurlencode('一括付与にはプラットフォーム管理者権限が必要です。') . '&type=ng');
        exit;
    }
    // 対象：いまの検索条件で絞り込んだ会員（scope=filtered）／有効会員すべて（scope=active）
    $scope = (string) ($_POST['scope'] ?? 'filtered');
    $ids = $scope === 'active'
        ? array_column(admin_search_members('', 'active'), 'id')
        : array_column(admin_search_members((string) ($_POST['q'] ?? ''), (string) ($_POST['status'] ?? '')), 'id');
    $r = admin_bulk_grant($ids, (string) ($_POST['op'] ?? ''), $_POST);
    header('Location: ' . $back . '&msg=' . rawurlencode($r['message']) . '&type=' . ($r['ok'] ? 'ok' : 'ng'));
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

<?php if ((int) ($tenant['is_admin'] ?? 0) === 1): ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
    <input type="hidden" name="action" value="bulk_grant">
    <input type="hidden" name="q" value="<?= e($keyword) ?>">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <div class="card__title">まとめて付与</div>
    <p class="hint" style="margin:0 0 10px;">
        ポイント・プラン・称号を、対象の会員へまとめて設定します。個別に変えたい場合は、一覧から会員を開いてください。
    </p>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
        <div>
            <label style="margin-top:0;">対象</label>
            <select name="scope">
                <option value="filtered">いまの検索結果（<?= count($members) ?>名）</option>
                <option value="active">有効会員すべて</option>
            </select>
        </div>
        <div>
            <label style="margin-top:0;">操作</label>
            <select name="op">
                <option value="points">ポイントを加算／減算</option>
                <option value="plan">プランを変更</option>
                <option value="title">称号を設定</option>
            </select>
        </div>
        <div>
            <label style="margin-top:0;">ポイント（±）</label>
            <input type="number" name="delta" value="0" style="max-width:110px;">
        </div>
        <div>
            <label style="margin-top:0;">プラン</label>
            <select name="plan">
                <option value="basic">ベーシック</option>
                <option value="premium">プレミアム</option>
            </select>
        </div>
        <div>
            <label style="margin-top:0;">称号</label>
            <select name="title">
                <option value="">自動（ポイント連動）</option>
                <?php foreach (assignable_titles() as $t): ?>
                    <option value="<?= e($t) ?>"><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:150px;">
            <label style="margin-top:0;">メモ（ポイントのみ・任意）</label>
            <input type="text" name="note" maxlength="200">
        </div>
    </div>
    <p style="margin:12px 0 0;">
        <button type="submit" class="btn"
                data-confirm="選んだ操作を対象の会員全員にまとめて適用します。元に戻すには同じ操作をやり直す必要があります。よろしいですか？">まとめて実行</button>
    </p>
    <p class="hint" style="margin:6px 0 0;">「操作」で選んだものだけが実行されます（使わない欄の値は無視されます）。</p>
</form>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
    <table style="width:100%;min-width:620px;border-collapse:collapse;font-size:.88rem;">
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
    </div>
    <?php if ($members === []): ?><p class="muted">該当する会員がいません。</p><?php endif; ?>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
