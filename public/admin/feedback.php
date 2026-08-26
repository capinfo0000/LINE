<?php

/**
 * 意見箱（運営側）。会員から届いた意見を読み、対応済みの印を付ける。CSVで書き出せる。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$filter = (string) ($_GET['f'] ?? 'all');
if (!in_array($filter, ['all', 'open', 'done'], true)) {
    $filter = 'all';
}

// CSV書き出し。画面の絞り込みをそのまま反映する。
if (($_GET['csv'] ?? '') === '1') {
    $csv = feedback_csv($filter);
    audit_log('admin.feedback_csv', ['filter' => $filter, 'bytes' => strlen($csv)]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="enlink_feedback_' . date('Ymd_His') . '.csv"');
    header('Content-Length: ' . strlen($csv));
    header('X-Content-Type-Options: nosniff');
    echo $csv;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'delete') {
        // 削除は元に戻せないため、プラットフォーム管理者だけに許す。
        // 画面のボタンも隠しているが、POSTを直接投げられても通らないようここで止める。
        if ((int) ($tenant['is_admin'] ?? 0) !== 1) {
            audit_log('authz.admin_deny', ['tenant' => $tenant['id'], 'path' => 'admin/feedback.delete']);
            header('Location: feedback?f=' . $filter . '&msg=' . rawurlencode('ご意見の削除にはプラットフォーム管理者権限が必要です。') . '&type=ng');
            exit;
        }
        $ok = feedback_delete($id);
        $note = $ok ? 'ご意見を削除しました。' : '対象が見つかりませんでした。';
    } else {
        $ok = feedback_set_handled($id, $action === 'handle');
        $note = $ok
            ? ($action === 'handle' ? '対応済みにしました。' : '未対応に戻しました。')
            : '対象が見つかりませんでした。';
    }
    header('Location: feedback?f=' . $filter . '&msg=' . rawurlencode($note) . '&type=' . ($ok ? 'ok' : 'ng'));
    exit;
}

$rows = feedback_list($filter);
$openCount = feedback_open_count();
$msg = (string) ($_GET['msg'] ?? '');
$msgType = ((string) ($_GET['type'] ?? 'ok')) === 'ng' ? 'ng' : 'ok';
$token = csrf_token();

$pageTitle = '意見箱';
$pageSub = '会員から届いたご意見（未対応 ' . $openCount . ' 件）';
$topActions = '<a class="btn btn--ghost btn--sm" href="feedback?f=' . e($filter) . '&csv=1">CSVで書き出す</a>';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
    <div class="card__title" style="margin:0;">絞り込み</div>
    <p style="display:flex;gap:8px;flex-wrap:wrap;margin:.6rem 0 0;">
        <?php foreach (['all' => 'すべて', 'open' => '未対応', 'done' => '対応済み'] as $k => $label): ?>
            <a class="btn btn--<?= $filter === $k ? '' : 'ghost' ?> btn--sm" href="feedback?f=<?= e($k) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </p>
    <p class="hint" style="margin:.6rem 0 0;">
        CSVは Excel でそのまま開けます（UTF-8・BOM付き）。上の絞り込みがCSVにも反映されます。
    </p>
</div>

<?php if ($rows === []): ?>
    <div class="card"><p style="margin:0;">該当するご意見はありません。</p></div>
<?php else: ?>
    <?php foreach ($rows as $f): ?>
        <?php $handled = (int) $f['handled'] === 1; ?>
        <div class="card">
            <div class="card__title" style="margin:0 0 6px;">
                <span class="badge badge--<?= $handled ? 'mute' : 'info' ?>"><?= $handled ? '対応済み' : '未対応' ?></span>
                <?= e(feedback_kind_label((string) $f['kind'])) ?>
                <span class="muted" style="font-weight:400;font-size:.82rem;">#<?= (int) $f['id'] ?></span>
            </div>
            <p class="muted" style="margin:0 0 8px;font-size:.82rem;">
                <?= e(date('Y/n/j H:i', (int) $f['created_at'])) ?>
                ／
                <?php if (($f['login_id'] ?? null) !== null): ?>
                    <a href="member_detail?id=<?= e((string) $f['member_id']) ?>"><code><?= e((string) $f['login_id']) ?></code></a>
                    <?= ((string) ($f['name_text'] ?? '')) !== '' ? e((string) $f['name_text']) : '' ?>
                <?php else: ?>
                    <span class="muted">（退会済みの会員）</span>
                <?php endif; ?>
                <?php if ($handled && $f['handled_at']): ?>
                    ／ 対応 <?= e(date('n/j H:i', (int) $f['handled_at'])) ?>
                <?php endif; ?>
            </p>
            <p style="margin:0;white-space:pre-wrap;line-height:1.7;"><?= e((string) $f['body']) ?></p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                    <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <input type="hidden" name="action" value="<?= $handled ? 'unhandle' : 'handle' ?>">
                    <button type="submit" class="btn btn--ghost btn--sm"><?= $handled ? '未対応に戻す' : '対応済みにする' ?></button>
                </form>
                <?php if ((int) ($tenant['is_admin'] ?? 0) === 1): ?>
                    <form method="post" style="margin:0;" data-confirm="このご意見を削除します。元に戻せません。よろしいですか？">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                        <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn btn--danger btn--sm">削除</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
