<?php

/**
 * 予約枠管理：説明会/個別面談の枠を作成・一覧・開閉する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $kind = (string) ($_POST['kind'] ?? '');
        $when = trim((string) ($_POST['start_at'] ?? ''));
        $cap = (int) ($_POST['capacity'] ?? 1);
        if (!in_array($kind, ['seminar', 'interview'], true) || $when === '') {
            $msg = '種別と日時を入力してください。';
            $msgType = 'ng';
        } else {
            try {
                $ts = (new DateTime($when, new DateTimeZone('Asia/Tokyo')))->getTimestamp();
                create_slot($kind, $ts, $cap > 0 ? $cap : 1);
                $msg = '予約枠を作成しました。';
            } catch (\Throwable $e) {
                $msg = '日時の形式が不正です。';
                $msgType = 'ng';
            }
        }
    } elseif ($action === 'toggle') {
        $sid = (string) ($_POST['slot_id'] ?? '');
        $open = (int) ($_POST['open'] ?? 0);
        $stmt = db()->prepare('UPDATE slots SET is_open = ? WHERE id = ?');
        $stmt->execute([$open, $sid]);
        $msg = '枠の受付状態を変更しました。';
    }
}

$slots = db()->query('SELECT * FROM slots ORDER BY start_at DESC LIMIT 200')->fetchAll();
$token = csrf_token();
$pageTitle = '予約枠管理';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<form method="post" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="create">
    <div><label>種別</label>
        <select name="kind"><option value="seminar">説明会</option><option value="interview">個別面談</option></select></div>
    <div><label>日時（JST）</label><input type="datetime-local" name="start_at"></div>
    <div><label>定員</label><input type="number" name="capacity" value="1" min="1" style="max-width:90px;"></div>
    <div><button type="submit" class="btn">枠を作成</button></div>
</form>

<div class="card">
    <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
        <tr style="text-align:left;border-bottom:1px solid var(--border);"><th style="padding:6px;">種別</th><th>日時(JST)</th><th>予約</th><th>Zoom</th><th>受付</th><th></th></tr>
        <?php foreach ($slots as $s):
            $jst = date('Y-m-d H:i', (int) $s['start_at'] + 9 * 3600); ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:6px;"><?= $s['kind'] === 'seminar' ? '説明会' : '個別面談' ?></td>
                <td><?= e($jst) ?></td>
                <td><?= (int) $s['booked_count'] ?>/<?= (int) $s['capacity'] ?></td>
                <td><?= $s['zoom_url'] ? '<span class="muted">発行済</span>' : '-' ?></td>
                <td><?= (int) $s['is_open'] === 1 ? '受付中' : '停止' ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="slot_id" value="<?= e($s['id']) ?>"><input type="hidden" name="open" value="<?= (int) $s['is_open'] === 1 ? 0 : 1 ?>">
                        <button class="btn btn--ghost" style="padding:2px 8px;"><?= (int) $s['is_open'] === 1 ? '停止' : '再開' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($slots === []): ?><p class="muted">枠がありません。</p><?php endif; ?>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
