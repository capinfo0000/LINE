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

// 非表示を含めて表示するか（旧連絡先の見直し用）。
$showHidden = isset($_GET['show_hidden']);

// 旧連絡先を隠す／表示に戻す（POST・本文送信より前に処理）。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_hide_unreachable'])) {
    csrf_verify($_POST['csrf_token'] ?? null);
    $n = hide_unreachable_contacts(300);
    $msg = "旧・不達の連絡先を {$n} 件隠しました（新LINEで見つからない連絡先）。";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_unhide_all'])) {
    csrf_verify($_POST['csrf_token'] ?? null);
    $n = unhide_all_contacts();
    $msg = "非表示を解除しました（{$n} 件を表示に戻しました）。";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_hide_one'])) {
    // ボタンの value に対象userIdが入る。
    csrf_verify($_POST['csrf_token'] ?? null);
    set_line_contact_hidden((string) $_POST['do_hide_one'], true);
    $msg = '連絡先を隠しました。';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_unhide_one'])) {
    csrf_verify($_POST['csrf_token'] ?? null);
    set_line_contact_hidden((string) $_POST['do_unhide_one'], false);
    $msg = '連絡先を表示に戻しました。';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_delete_one'])) {
    // 連絡先を手動で完全削除（会員アカウントは残し、LINE紐付けのみ解除）。
    csrf_verify($_POST['csrf_token'] ?? null);
    $msg = delete_line_contact((string) $_POST['do_delete_one'])
        ? '連絡先を削除しました。'
        : '連絡先が見つかりませんでした。';
}

// 表示名が未取得の友だちに、LINEプロフィールから名前を補完（旧チャネル分は取得できずスキップ）。
line_backfill_contact_names(30);

// 非表示の件数（トグル表示用）。
$hiddenCount = (int) db()->query('SELECT COUNT(*) FROM line_contacts WHERE hidden = 1')->fetchColumn();

// 友だち一覧（公式LINE追加済み）。既定は非表示を除外。
$friendSql = 'SELECT line_user_id, display_name, member_id, onboarding_state, hidden FROM line_contacts';
if (!$showHidden) {
    $friendSql .= ' WHERE hidden = 0';
}
$friendSql .= ' ORDER BY created_at DESC LIMIT 500';
$friends = db()->query($friendSql)->fetchAll();
// 添付できる説明会・面談の枠（未来）。
$slots = db()->prepare('SELECT * FROM slots WHERE start_at > ? ORDER BY start_at ASC LIMIT 50');
$slots->execute([time()]);
$slots = $slots->fetchAll();

// 「全会議日程の提案」再送用テキスト（現在予約可能な説明会＋個別面談の日程一覧）。
// まだ予約していない友だちにも、日程を提案して予約を促せる。
$wd = ['日', '月', '火', '水', '木', '金', '土'];
$fmtSlots = static function (array $rows) use ($wd): array {
    $out = [];
    foreach ($rows as $s) {
        $ts = (int) $s['start_at'];
        $out[] = '・' . date('n/j', $ts) . '（' . $wd[(int) date('w', $ts)] . '）' . date(' H:i', $ts);
    }
    return $out;
};
$seminarOpen = open_slots('seminar', 12);
$interviewOpen = open_slots('interview', 12);
$scheduleText = '';
if ($seminarOpen !== [] || $interviewOpen !== []) {
    $lines = ['【説明会・個別面談の日程】', 'ご希望の会に合わせて、このトークに「説明会」または「面談」と送るとご予約いただけます。'];
    if ($seminarOpen !== []) {
        $lines[] = '';
        $lines[] = '■ 説明会';
        $lines = array_merge($lines, $fmtSlots($seminarOpen));
    }
    if ($interviewOpen !== []) {
        $lines[] = '';
        $lines[] = '■ 個別面談';
        $lines = array_merge($lines, $fmtSlots($interviewOpen));
    }
    $scheduleText = implode("\n", $lines);
}

/** オンボーディング状態を日本語ラベルに。 */
function friend_state_label(string $state): string
{
    return [
        'added' => '友だち', 'booked_seminar' => '説明会予約', 'seminar_done' => '説明会済',
        'booked_interview' => '面談予約', 'interview_done' => '面談済', 'approved' => '承認済',
        'payment_sent' => '決済案内済', 'paid' => '入会済',
    ][$state] ?? $state;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_onboarding'])) {
    // 初回メッセージ（あいさつ＋説明会の日程）を選択宛先へ手動送信。
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($targetUids === []) {
        $msg = '宛先を1件以上選択してください。';
        $msgType = 'ng';
    } else {
        $sent = 0;
        foreach ($targetUids as $uid) {
            if (line_push($uid, line_onboarding_messages())) {
                $sent++;
            }
        }
        audit_log('line.onboarding_send', ['targets' => count($targetUids), 'sent' => $sent]);
        $msg = "初回メッセージを送信しました（送信 {$sent} 件 / 対象 " . count($targetUids) . " 件）。";
        $uids = [];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_issue'])) {
    // 会員資格（ID/PW）を即時発行して選択宛先へLINEで送信（決済・説明会を経ない手動発行）。
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($targetUids === []) {
        $msg = '宛先を1件以上選択してください。';
        $msgType = 'ng';
    } else {
        $issued = 0;
        $linked = 0;
        $failed = 0;
        foreach ($targetUids as $uid) {
            $r = provision_free_member_from_contact($uid);
            if (($r['status'] ?? '') === 'done') {
                $issued++;
            } elseif (($r['status'] ?? '') === 'linked') {
                $linked++;
            } else {
                $failed++;
            }
        }
        audit_log('line.issue_credentials', ['targets' => count($targetUids), 'issued' => $issued, 'linked' => $linked, 'failed' => $failed]);
        $msg = "会員資格を発行して送信しました（新規発行 {$issued} 件 / 既に会員 {$linked} 件"
             . ($failed > 0 ? " / 失敗 {$failed} 件" : '') . " / 対象 " . count($targetUids) . " 件）。";
        $msgType = $failed > 0 ? 'ng' : 'ok';
        $uids = [];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_slots'])) {
    // 日程（予約ボタン付きカード）を選択宛先へ手動送信。
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($targetUids === []) {
        $msg = '宛先を1件以上選択してください。';
        $msgType = 'ng';
    } else {
        $flex = line_slots_flex(['seminar', 'interview'], 'ご予約日程のご案内');
        $lead = line_text('ご予約可能な日程です。ご希望の日程の「この日程を選ぶ」からお進みください。');
        $sent = 0;
        foreach ($targetUids as $uid) {
            if (line_push($uid, [$lead, $flex])) {
                $sent++;
            }
        }
        audit_log('line.slots_send', ['targets' => count($targetUids), 'sent' => $sent]);
        $msg = "予約ボタン付きの日程を送信しました（送信 {$sent} 件 / 対象 " . count($targetUids) . " 件）。";
        $uids = [];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
$pageTitle = 'LINE配信';
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
    <!-- 連絡先の管理（旧連絡先を隠す）。メインフォームと入れ子にならないよう独立フォームで置く。 -->
    <div class="card" style="background:#f8fafc;">
        <div class="card__title" style="margin-top:0;">連絡先の整理</div>
        <p class="muted" style="font-size:.85rem;margin:.2rem 0;">公式LINEを切り替えると、旧LINEの連絡先は送信しても届きません。新LINEで見つからない連絡先を一覧から隠せます。</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <button type="submit" name="do_hide_unreachable" value="1" class="btn btn--sm"
                        data-confirm="新LINEで見つからない（旧・不達の）連絡先を一覧から隠します。よろしいですか？（あとで戻せます）">🧹 旧・不達の連絡先を隠す</button>
            </form>
            <?php if ($hiddenCount > 0): ?>
                <a class="btn btn--ghost btn--sm" href="line_send.php<?= $showHidden ? '' : '?show_hidden=1' ?>"><?= $showHidden ? '非表示を隠す' : "非表示 {$hiddenCount} 件を表示" ?></a>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                    <button type="submit" name="do_unhide_all" value="1" class="btn btn--ghost btn--sm"
                            data-confirm="非表示の連絡先をすべて表示に戻しますか？">すべて表示に戻す</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- 個別の隠す/戻す用の独立フォーム（各行のボタンから form 属性で参照） -->
    <form id="cform" method="post" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
    </form>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="stage" value="preview">

        <div class="card">
            <div class="card__title">宛先</div>
            <div class="recip-toolbar">
                <button type="button" class="btn btn--ghost btn--sm" data-recipient-select="all">全員</button>
                <button type="button" class="btn btn--ghost btn--sm" data-recipient-select="member">会員のみ</button>
                <button type="button" class="btn btn--ghost btn--sm" data-recipient-select="nonmember">未入会のみ</button>
                <button type="button" class="btn btn--ghost btn--sm" data-recipient-select="none">解除</button>
                <span class="muted" style="margin-left:auto;font-size:.85rem;">選択中 <strong data-recipient-count style="color:var(--accent);">0</strong> / <?= count($friends) ?> 名</span>
            </div>
            <div class="recip-list">
                <?php if ($friends === []): ?>
                    <p class="muted" style="margin:0;padding:12px;">まだ友だちがいません（公式LINEの友だち追加で増えます）。</p>
                <?php else: foreach ($friends as $f):
                    $u = (string) $f['line_user_id'];
                    $name = (string) ($f['display_name'] ?? '');
                    $label = $name !== '' ? $name : ('友だち ' . substr($u, 0, 8));
                    $isMember = !empty($f['member_id']);
                    $isHidden = (int) ($f['hidden'] ?? 0) === 1;
                    ?>
                    <div class="recip" style="display:flex;align-items:center;gap:8px;<?= $isHidden ? 'opacity:.55;' : '' ?>">
                        <label style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;margin:0;cursor:pointer;">
                            <input type="checkbox" class="js-recipient" name="uids[]" value="<?= e($u) ?>" data-member="<?= $isMember ? '1' : '0' ?>" <?= in_array($u, $uids, true) ? 'checked' : '' ?>>
                            <span class="recip__name"><?= e($label) ?></span>
                            <span class="recip__meta">
                                <?php if ($isMember): ?><span class="chipmini chipmini--ok">会員</span><?php endif; ?>
                                <span class="chipmini"><?= e(friend_state_label((string) $f['onboarding_state'])) ?></span>
                                <?php if ($isHidden): ?><span class="chipmini">非表示</span><?php endif; ?>
                            </span>
                        </label>
                        <?php if ($isHidden): ?>
                            <button form="cform" type="submit" name="do_unhide_one" value="<?= e($u) ?>" class="btn btn--ghost btn--sm">戻す</button>
                        <?php else: ?>
                            <button form="cform" type="submit" name="do_hide_one" value="<?= e($u) ?>" class="btn btn--ghost btn--sm">隠す</button>
                        <?php endif; ?>
                        <button form="cform" type="submit" name="do_delete_one" value="<?= e($u) ?>" class="btn btn--ghost btn--sm" style="color:var(--dng);border-color:var(--dng-bg);"
                                data-confirm="この連絡先を完全に削除します（元に戻せません）。会員アカウントは残り、LINEの紐付けだけ解除されます。よろしいですか？">削除</button>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <p class="muted" style="font-size:.82rem;margin:8px 0 0;">上のボタンで一括選択、個別チェックで微調整できます。</p>
        </div>

        <div class="card">
            <div class="card__title">本文</div>
            <div style="margin-bottom:8px;display:flex;gap:6px;flex-wrap:wrap;">
                <span class="muted" style="align-self:center;font-size:.82rem;">定型文：</span>
                <?php if ($scheduleText !== ''): ?>
                    <button type="button" class="btn btn--ghost" style="padding:3px 10px;font-size:.82rem;border-color:var(--accent);color:var(--accent);font-weight:700;"
                            data-fill-text="text" data-text="<?= e($scheduleText) ?>">📅 全日程を提案（再送）</button>
                <?php endif; ?>
                <?php foreach ($templates as $tpl): ?>
                    <button type="button" class="btn btn--ghost" style="padding:3px 10px;font-size:.82rem;"
                            data-fill-text="text" data-text="<?= e($tpl['text']) ?>"><?= e($tpl['label']) ?></button>
                <?php endforeach; ?>
            </div>
            <textarea name="text" rows="6" maxlength="1000" placeholder="お知らせ内容を入力"><?= e($text) ?></textarea>
            <label style="margin-top:10px;">説明会の枠を案内に添付（任意）</label>
            <select name="slot_id">
                <option value="">添付しない</option>
                <?php foreach ($slots as $s):
                    $kl = $s['kind'] === 'seminar' ? '説明会' : '個別面談';
                    $when = date('Y-m-d H:i', (int) $s['start_at']);
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
            <button type="submit" name="do_slots" value="1" class="btn btn--ghost"
                    data-confirm="選択した宛先へ『日程（予約ボタン付きカード）』を送信します。よろしいですか？">📅 日程を送る（予約ボタン付き）</button>
            <button type="submit" name="do_onboarding" value="1" class="btn btn--ghost"
                    data-confirm="選択した宛先へ『初回メッセージ（あいさつ＋説明会の日程・予約ボタン付き）』を送信します。よろしいですか？">初回メッセージを送る</button>
            <button type="submit" name="do_issue" value="1" class="btn btn--ghost" style="border-color:#b45309;color:#b45309;font-weight:700;"
                    data-confirm="選択した宛先に『会員資格（ID/パスワード）』を即時発行してLINEで送信します。決済・説明会を経ない手動発行です。よろしいですか？">🎫 会員資格を発行して送信</button>
            <p class="muted" style="font-size:.82rem;margin:8px 0 0;">
                「📅 日程を送る」＝説明会の日程を<strong>カード＋予約ボタン付き</strong>で送信（相手はタップで日程を選んで予約）。<br>
                「初回メッセージを送る」＝友だち追加時と同じ、あいさつ＋説明会の予約ボタン付きメッセージ。<br>
                「🎫 会員資格を発行して送信」＝選択した相手に<strong>会員ID/パスワードを即時発行</strong>してLINEで送信（<strong>決済・説明会を経ない手動発行</strong>／既に会員の相手は再発行しません）。<br>
                （日程・初回・発行の各ボタンは本文・添付を使いません）</p>
        </div>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
