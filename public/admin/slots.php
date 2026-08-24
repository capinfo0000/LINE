<?php

/**
 * 説明会・面談の設定：説明会/個別面談の開催枠を作成・一覧・開閉する。
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
                $sid = create_slot($kind, $ts, $cap > 0 ? $cap : 1);
                $created = find_slot($sid);
                if (!empty($created['zoom_url'])) {
                    $msg = '枠を作成し、Zoom会議URLを発行しました。予約者にはこのURLが自動で届きます。';
                } elseif (zoom_enabled()) {
                    $msg = '枠を作成しました。ただしZoom会議の発行に失敗したため、最初の予約時に再発行を試みます。';
                    $msgType = 'ng';
                } else {
                    $msg = '枠を作成しました（Zoom未設定のため会議URLは手動運用です）。';
                }
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
    } elseif ($action === 'delete_slot') {
        // 枠の削除。予約が入っている枠は誤操作防止のため削除させない（先に受付停止/個別対応）。
        $sid = (string) ($_POST['slot_id'] ?? '');
        $slot = find_slot($sid);
        if ($slot === null) {
            $msg = '対象の枠が見つかりませんでした。';
            $msgType = 'ng';
        } elseif ((int) ($slot['booked_count'] ?? 0) > 0) {
            $msg = 'この枠には申込者がいるため削除できません。まず「停止」で受付を止め、申込者へ個別にご連絡ください。';
            $msgType = 'ng';
        } else {
            db()->prepare('DELETE FROM slot_url_pending WHERE slot_id = ?')->execute([$sid]);
            db()->prepare('DELETE FROM slots WHERE id = ?')->execute([$sid]);
            audit_log('admin.slot_deleted', ['slot' => $sid]);
            $msg = '枠を削除しました。';
        }
    } elseif ($action === 'zoom_test') {
        $d = zoom_diagnose();
        $msg = $d['message'];
        $msgType = $d['ok'] ? 'ok' : 'ng';
    } elseif ($action === 'issue' || $action === 'rezoom') {
        // issue  : 未発行の枠にZoomリンクを新規発行（既存があれば何もしない）。
        // rezoom : 発行済みリンクが壊れた等の際に強制再発行。
        // どちらも「正しく発行できた場合のみ」申込者へLINE送信する。
        $sid = (string) ($_POST['slot_id'] ?? '');
        $slot = find_slot($sid);
        if (!zoom_enabled()) {
            $msg = 'Zoomが未設定のため発行できません。.envのZoom設定を確認してください。';
            $msgType = 'ng';
        } elseif ($slot === null) {
            $msg = '対象の枠が見つかりません。';
            $msgType = 'ng';
        } elseif ($action === 'issue' && !empty($slot['zoom_url'])) {
            $msg = 'この枠は既にZoom発行済みです。（壊れている場合は「再発行」をお使いください）';
        } else {
            // issue は未発行のみ発行（ensure=冪等）、rezoom は強制再発行。
            $newUrl = $action === 'issue' ? ensure_slot_zoom($slot) : regenerate_slot_zoom($sid);
            if ($newUrl === null) {
                $msg = 'Zoom会議の発行に失敗しました。時間をおいて再度お試しください。（送信は行っていません）';
                $msgType = 'ng';
            } else {
                // 発行成功 → 当該枠の予約(booked)にもURLを反映し、申込者へ送信。
                db()->prepare("UPDATE bookings SET zoom_url = ? WHERE slot_id = ? AND status = 'booked'")
                    ->execute([$newUrl, $sid]);
                $r = push_zoom_url_to_slot_bookings($sid, $newUrl);
                $head = $action === 'issue' ? 'Zoom会議URLを発行しました。' : 'Zoom会議URLを再発行しました。';
                if ($r['total'] === 0) {
                    $msg = $head . '（申込者はまだいません。以後の予約者には自動で届きます）';
                } else {
                    $msg = $head . ' 申込者 ' . $r['total'] . ' 名中 ' . $r['sent'] . ' 名へLINE送信しました。';
                    if ($r['sent'] < $r['total']) {
                        $msg .= '（未送信分はLINE連携が無い等。URL: ' . $newUrl . ' を手動でご案内ください）';
                    }
                }
            }
        }
    }
}

$slots = db()->query('SELECT * FROM slots ORDER BY start_at DESC LIMIT 200')->fetchAll();
// 開催3時間経過で「過去」扱い（当日・開催中はまだ操作対象に残す）。
$pastCutoff = time() - 3 * 3600;
$activeSlots = [];
$pastSlots = [];
foreach ($slots as $s) {
    if ((int) $s['start_at'] >= $pastCutoff) {
        $activeSlots[] = $s;
    } else {
        $pastSlots[] = $s;
    }
}
// アクティブは開催が近い順（昇順）で見やすく。
usort($activeSlots, static fn ($a, $b) => (int) $a['start_at'] <=> (int) $b['start_at']);
$token = csrf_token();
$pageTitle = '説明会・面談の設定';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
    <span class="muted">Zoom連携の状態を確認できます（.env設定後の切り分け用）。</span>
    <form method="post" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="zoom_test">
        <button type="submit" class="btn btn--ghost">Zoom接続テスト</button>
    </form>
</div>

<form method="post" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="create">
    <div><label>種別</label>
        <select name="kind"><option value="seminar">説明会</option><option value="interview">個別面談</option></select></div>
    <div><label>日時（JST）</label><input type="datetime-local" name="start_at"></div>
    <div><label>定員</label><input type="number" name="capacity" value="1" min="1" style="max-width:90px;"></div>
    <div><button type="submit" class="btn">枠を作成</button></div>
</form>

<div class="card">
    <div class="card__title" style="margin-bottom:8px;">開催予定・開催中</div>
    <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
        <tr style="text-align:left;border-bottom:1px solid var(--border);"><th style="padding:6px;">種別</th><th>日時(JST)</th><th>申込</th><th>Zoom</th><th>受付</th><th></th></tr>
        <?php foreach ($activeSlots as $s):
            $jst = date('Y-m-d H:i', (int) $s['start_at'] + 9 * 3600); ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:6px;"><?= $s['kind'] === 'seminar' ? '説明会' : '個別面談' ?></td>
                <td><?= e($jst) ?></td>
                <td><?= (int) $s['booked_count'] ?>/<?= (int) $s['capacity'] ?></td>
                <td><?php if (!empty($s['zoom_url'])): ?><a href="<?= e($s['zoom_url']) ?>" target="_blank" rel="noopener">会議URL</a><?php else: ?><span class="muted">未発行</span><?php endif; ?></td>
                <td><?= (int) $s['is_open'] === 1 ? '受付中' : '停止' ?></td>
                <td style="white-space:nowrap;">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="slot_id" value="<?= e($s['id']) ?>"><input type="hidden" name="open" value="<?= (int) $s['is_open'] === 1 ? 0 : 1 ?>">
                        <button class="btn btn--ghost" style="padding:2px 8px;"><?= (int) $s['is_open'] === 1 ? '停止' : '再開' ?></button>
                    </form>
                    <form method="post" style="display:inline;" data-confirm="この枠を削除します。元に戻せません。よろしいですか？">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="delete_slot">
                        <input type="hidden" name="slot_id" value="<?= e($s['id']) ?>">
                        <button class="btn btn--ghost" style="padding:2px 8px;color:var(--dng);">削除</button>
                    </form>
                    <?php if (empty($s['zoom_url'])):
                        $cf = (int) $s['booked_count'] > 0
                            ? 'Zoomリンクを発行し、申込者' . (int) $s['booked_count'] . '名へLINE送信します。よろしいですか？'
                            : 'Zoomリンクを発行します。よろしいですか？'; ?>
                    <form method="post" style="display:inline;" data-confirm="<?= e($cf) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="issue">
                        <input type="hidden" name="slot_id" value="<?= e($s['id']) ?>">
                        <button class="btn" style="padding:2px 8px;">Zoom発行</button>
                    </form>
                    <?php else:
                        // 発行済みの枠には常に再発行ボタンを出す（申込者0名でも再発行だけ実行できる）。
                        $cf2 = (int) $s['booked_count'] > 0
                            ? 'Zoomリンクを再発行し、申込者' . (int) $s['booked_count'] . '名へ新URLをLINE送信します。よろしいですか？'
                            : 'Zoomリンクを再発行します（申込者がいないためLINE送信はありません）。よろしいですか？'; ?>
                    <form method="post" style="display:inline;" data-confirm="<?= e($cf2) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="rezoom">
                        <input type="hidden" name="slot_id" value="<?= e($s['id']) ?>">
                        <button class="btn" style="padding:2px 8px;">リンク再発行＋送信</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($activeSlots === []): ?><p class="muted">開催予定の枠がありません。</p><?php endif; ?>
</div>

<?php if ($pastSlots !== []): ?>
<div class="card">
    <details>
        <summary style="cursor:pointer;font-weight:700;">過去の開催（<?= count($pastSlots) ?>件）</summary>
        <p class="muted" style="margin:8px 0;">過去分のZoomリンクは表示しません。</p>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
            <tr style="text-align:left;border-bottom:1px solid var(--border);"><th style="padding:6px;">種別</th><th>日時(JST)</th><th>申込</th><th>受付</th></tr>
            <?php foreach ($pastSlots as $s):
                $jst = date('Y-m-d H:i', (int) $s['start_at'] + 9 * 3600); ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:6px;"><?= $s['kind'] === 'seminar' ? '説明会' : '個別面談' ?></td>
                    <td><?= e($jst) ?></td>
                    <td><?= (int) $s['booked_count'] ?>/<?= (int) $s['capacity'] ?></td>
                    <td class="muted">終了</td>
                </tr>
            <?php endforeach; ?>
        </table>
    </details>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
