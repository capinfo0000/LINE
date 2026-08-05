<?php

/**
 * 友だち配信（LINE Push）— 公式LINEの友だち(line_contacts)へ、全員 or 個別選択で配信する。
 * 説明会・面談の枠を選ぶと、日時＋（発行済みなら）Zoom会議URLを本文に添付できる。
 * 送信前に対象件数（＝課金通数）を確認する二段階フロー。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';
$msgType = 'ok';

// 友だち一覧（公式LINE追加済み）。
$friends = db()->query(
    'SELECT line_user_id, display_name, member_id, onboarding_state FROM line_contacts ORDER BY created_at DESC LIMIT 500'
)->fetchAll();
// 添付できる説明会・面談の枠（未来）。
$slots = db()->prepare('SELECT * FROM slots WHERE start_at > ? ORDER BY start_at ASC LIMIT 50');
$slots->execute([time()]);
$slots = $slots->fetchAll();

/** 本文に枠の案内（再送と同じ固定文面）を付与する。 */
function line_send_compose(string $text, string $slotId): string
{
    if ($slotId === '') {
        return $text;
    }
    $slot = find_slot($slotId);
    if ($slot === null) {
        return $text;
    }
    // 再送と共通の固定文面を末尾に付与する。
    $notice = slot_zoom_notice_body($slot);
    return trim($text) !== '' ? trim($text) . "\n\n" . $notice : $notice;
}

// 定型文（クリックで本文にセット）。文言はここを編集すれば変更できる。
$templates = [
    ['label' => '無料説明会のご案内', 'text' => "この度はEnlinkにご興味いただきありがとうございます。\n無料のオンライン説明会（Zoom・約30分）を実施しています。サービス内容や活用方法をご紹介しますので、ご都合のよい日程でぜひご参加ください。ご質問だけでも歓迎です。"],
    ['label' => '入会のご案内',       'text' => "Enlinkは月額制の会員コミュニティです。\n会員サイトでの人脈マッチング・条件検索、交流の場などをご利用いただけます。ご入会をご検討の方は、こちらのご案内をご確認のうえお手続きください。ご不明点はお気軽にお問い合わせください。"],
    ['label' => '面談のご案内',       'text' => "個別面談（オンライン・約30分）のご案内です。\nあなたのご状況に合わせて、活用方法やご入会について個別にご説明します。ご希望の日程をお選びください。"],
    ['label' => 'リマインド',         'text' => "【リマインド】お申し込みいただいた日程が近づいてまいりました。\n開始時刻になりましたら、ご案内のURLからご参加ください。当日お会いできるのを楽しみにしております。"],
];

$text = (string) ($_POST['text'] ?? '');
$slotId = (string) ($_POST['slot_id'] ?? '');
$uids = array_values(array_filter((array) ($_POST['uids'] ?? []), 'is_string'));
$stage = (string) ($_POST['stage'] ?? '');

// 実際の宛先＝チェックされた友だち（存在するもののみ）。「全員」は全チェックで表現。
$allUids = array_map(static fn ($f) => (string) $f['line_user_id'], $friends);
$targetUids = array_values(array_intersect($allUids, $uids));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $finalText = line_send_compose($text, $slotId);

    if ($stage === 'send') {
        if (trim($finalText) === '') {
            $msg = '本文が空です。';
            $msgType = 'ng';
            $stage = '';
        } elseif ($targetUids === []) {
            $msg = '宛先が選択されていません。';
            $msgType = 'ng';
            $stage = '';
        } else {
            $sent = 0;
            foreach ($targetUids as $uid) {
                if (line_push($uid, [line_text($finalText)])) {
                    $sent++;
                }
            }
            audit_log('line.friend_send', ['targets' => count($targetUids), 'sent' => $sent]);
            $msg = "配信しました（送信 {$sent} 件 / 対象 " . count($targetUids) . " 件）。";
            // 未発行の枠を添付して送った場合は、発行時に自動でURLを送るため宛先を保留登録。
            if ($slotId !== '') {
                $atSlot = find_slot($slotId);
                if ($atSlot !== null && empty($atSlot['zoom_url'])) {
                    enqueue_slot_url_pending($slotId, $targetUids);
                    $msg .= ' この枠はZoom未発行のため、発行時に参加URLを自動でお送りします。';
                }
            }
            $msgType = 'ok';
            $stage = '';
            $text = '';
            $slotId = '';
            $uids = [];
        }
    } elseif ($stage === 'preview') {
        if (trim($finalText) === '') {
            $msg = '本文を入力してください。';
            $msgType = 'ng';
            $stage = '';
        } elseif ($targetUids === []) {
            $msg = '宛先を1件以上選択してください（または「全員」を選択）。';
            $msgType = 'ng';
            $stage = '';
        }
    }
}

$token = csrf_token();
$pageTitle = '友だち配信';
$pageSub = '公式LINE友だち: ' . count($friends) . ' 名';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<?php if ($stage === 'preview'): $finalText = line_send_compose($text, $slotId); ?>
    <div class="card">
        <div class="card__title">配信内容の確認</div>
        <p style="white-space:pre-wrap;border:1px solid var(--border);border-radius:8px;padding:10px;background:#f9fafb;"><?= e($finalText) ?></p>
        <p class="muted">宛先 <strong><?= count($targetUids) ?> 名</strong>（＝<?= count($targetUids) ?> 通・<strong>1通ごとに課金</strong>）へ送信します。</p>
        <form method="post" style="display:inline;" data-confirm="この内容を <?= count($targetUids) ?> 名へ配信します。よろしいですか？">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="stage" value="send">
            <input type="hidden" name="text" value="<?= e($text) ?>"><input type="hidden" name="slot_id" value="<?= e($slotId) ?>">
            <?php foreach ($targetUids as $u): ?><input type="hidden" name="uids[]" value="<?= e($u) ?>"><?php endforeach; ?>
            <button class="btn">この内容で配信する</button>
        </form>
        <a class="btn btn--ghost" href="line_send.php">やめる</a>
    </div>
<?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="stage" value="preview">

        <div class="card">
            <div class="card__title">宛先（チェックした友だちへ送信）</div>
            <label style="font-weight:600;border-bottom:1px solid var(--border);display:block;padding-bottom:6px;">
                <input type="checkbox" data-check-all="uids[]"> 全員を選択／解除（<?= count($friends) ?> 名）
            </label>
            <div style="max-height:340px;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:8px;margin-top:8px;">
                <?php if ($friends === []): ?>
                    <p class="muted" style="margin:0;">まだ友だちがいません（公式LINEの友だち追加で増えます）。</p>
                <?php else: foreach ($friends as $f):
                    $u = (string) $f['line_user_id'];
                    $name = (string) ($f['display_name'] ?? '');
                    $label = $name !== '' ? $name : ('友だち ' . substr($u, 0, 8));
                    ?>
                    <label style="display:block;font-weight:normal;padding:3px 0;">
                        <input type="checkbox" name="uids[]" value="<?= e($u) ?>" <?= in_array($u, $uids, true) ? 'checked' : '' ?>>
                        <?= e($label) ?>
                        <span class="muted" style="font-size:.78rem;"><?= e((string) $f['onboarding_state']) ?><?= $f['member_id'] ? '・会員' : '' ?></span>
                    </label>
                <?php endforeach; endif; ?>
            </div>
            <p class="muted" style="font-size:.82rem;margin-bottom:0;">「全員を選択」で一斉、個別にチェックすれば選んだ人だけに送れます。</p>
        </div>

        <div class="card">
            <div class="card__title">本文</div>
            <div style="margin-bottom:8px;display:flex;gap:6px;flex-wrap:wrap;">
                <span class="muted" style="align-self:center;font-size:.82rem;">定型文：</span>
                <?php foreach ($templates as $tpl): ?>
                    <button type="button" class="btn btn--ghost" style="padding:3px 10px;font-size:.82rem;"
                            data-fill-text="text" data-text="<?= e($tpl['text']) ?>"><?= e($tpl['label']) ?></button>
                <?php endforeach; ?>
            </div>
            <textarea name="text" rows="6" maxlength="1000" placeholder="お知らせ内容を入力"><?= e($text) ?></textarea>
            <label style="margin-top:10px;">説明会・面談の枠を案内に添付（任意）</label>
            <select name="slot_id">
                <option value="">添付しない</option>
                <?php foreach ($slots as $s):
                    $kl = $s['kind'] === 'seminar' ? '説明会' : '個別面談';
                    $when = date('Y-m-d H:i', (int) $s['start_at'] + 9 * 3600);
                    $zoom = !empty($s['zoom_url']) ? '（URL有）' : '（URL未発行）';
                    ?>
                    <option value="<?= e($s['id']) ?>"<?= $slotId === $s['id'] ? ' selected' : '' ?>><?= e("{$kl} {$when} {$zoom}") ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted" style="font-size:.82rem;">枠を選ぶと、本文の末尾に日時＋（発行済みなら）Zoom会議URLが自動で付きます。</p>
        </div>

        <div class="card">
            <p class="muted" style="margin-top:0;"><strong>宛先1件ごとに1通課金</strong>されます。送信前に確認画面が出ます。</p>
            <button type="submit" class="btn">確認画面へ</button>
        </div>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
