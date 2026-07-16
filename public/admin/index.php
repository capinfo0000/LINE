<?php

/**
 * 参加者管理ダッシュボード（ログイン中テナント専用）。
 *
 * 名簿は運営者自身の Stripe アカウントから取得する（DBには参加者を持たない）。
 * - 自分のイベントを選んで参加者一覧を表示
 * - 事前決済の返金（全額＝キャンセル／一部）、当日支払いの集金確認・取消
 * - CSV ダウンロード
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
// 名簿は運営者の Stripe から取得する。画面登録鍵→Connect→プラットフォームの順で文脈確立。
$account = stripe_resolve_tenant($tenant);

$events = tenant_events($tenant['id']);
$selectedId = (string) ($_GET['event_id'] ?? ($events[0]['id'] ?? ''));
$selectedEvent = $selectedId !== '' ? find_event($selectedId) : null;
// 他テナントのイベントIDを指定されても見せない
if ($selectedEvent !== null && $selectedEvent['tenant_id'] !== $tenant['id']) {
    $selectedEvent = null;
}

// 直前の操作の結果メッセージ（リダイレクトで引き継ぎ）
$flash = (string) ($_GET['msg'] ?? '');
$flashType = (string) ($_GET['type'] ?? '');

$participants = [];
$fetchError = '';
$totalCount = 0;
$prepaidCount = 0;
$onsiteCount = 0;
$onsiteCollectedCount = 0;
$collected = 0;   // 事前決済の入金合計
$refunded = 0;    // 返金合計
$onsiteDue = 0;   // 当日支払い予定（未収）合計
$attendedCount = 0; // 出席確認済みの申込数（頭数ではなく行数）
$headcount = 0;     // 参加予定の頭数（返金済みを除く party_size 合計）

if ($selectedEvent !== null && !stripe_ready_for_tenant($tenant)) {
    $fetchError = 'Stripe キーが未設定のため名簿を取得できません。「Stripe設定」から鍵を登録してください。';
} elseif ($selectedEvent !== null) {
    try {
        $participants = fetch_event_participants($selectedId, $account);
        $totalCount = count($participants);
        foreach ($participants as $p) {
            if (!empty($p['attended'])) {
                $attendedCount++;
            }
            if (empty($p['fully_refunded'])) {
                $headcount += max(1, (int) $p['party_size']);
            }
            if (($p['payment_type'] ?? 'prepay') === 'onsite') {
                $onsiteCount++;
                if (!empty($p['collected'])) {
                    $onsiteCollectedCount++;
                } else {
                    $onsiteDue += $p['amount'];
                }
            } else {
                $prepaidCount++;
                $collected += $p['amount'];
                $refunded += $p['amount_refunded'];
            }
        }
    } catch (\Throwable $ex) {
        $fetchError = 'Stripe から名簿を取得できませんでした: ' . $ex->getMessage();
        error_log('参加者取得失敗: ' . $ex->getMessage());
    }
}

$token = csrf_token();

$pageTitle = '参加者管理';
$pageSub = '名簿はあなたの Stripe アカウントから取得しています（参加者DBは持ちません）';
require __DIR__ . '/_app_header.php';
?>
<style nonce="<?= e(csp_nonce()) ?>">
    .bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin: 0 0 18px; }
    .bar select { width: auto; }
    .refund-form { display: flex; gap: 6px; align-items: center; }
    .refund-form input { width: 92px; }
</style>

<?php if ($flash !== ''): ?>
    <div class="flash <?= $flashType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<?php if (empty($events)): ?>
    <div class="err">まだイベントがありません。<a href="events.php">イベント管理</a>から登録してください。</div>
<?php else: ?>
<form method="get" class="bar">
    <label style="margin:0; font-weight:600;">イベント：</label>
    <select name="event_id" class="js-autosubmit">
        <?php foreach ($events as $ev): ?>
            <option value="<?= e($ev['id']) ?>" <?= $ev['id'] === $selectedId ? 'selected' : '' ?>>
                <?= e($ev['name'] ?? $ev['id']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn">表示</button></noscript>
    <?php if ($selectedEvent !== null): ?>
        <a class="btn btn--ghost" href="export.php?event_id=<?= e($selectedId) ?>">CSV ダウンロード</a>
        <a class="btn btn--ghost" href="../apply.php?event_id=<?= e($selectedId) ?>" target="_blank">申込ページを開く</a>
    <?php endif; ?>
</form>
<?php endif; ?>

<?php if ($selectedEvent !== null): ?>
    <p class="muted">
        <?= e($selectedEvent['date'] ?? '') ?>　<?= e($selectedEvent['place'] ?? '') ?>
        <?php if (!empty($selectedEvent['capacity'])): ?>　／ 定員目安: <?= (int) $selectedEvent['capacity'] ?> 名<?php endif; ?>
    </p>
<?php endif; ?>

<?php if ($fetchError !== ''): ?>
    <p class="err"><?= e($fetchError) ?></p>
<?php elseif ($selectedEvent === null): ?>
    <p class="err">イベントが選択されていません。</p>
<?php else: ?>
    <?php $cur0 = $selectedEvent['currency'] ?? 'jpy'; ?>
    <?php $cap = (int) ($selectedEvent['capacity'] ?? 0); ?>
    <div class="stat-grid">
        <div class="stat"><span class="stat__num accent"><?= $headcount ?><?= $cap > 0 ? ' / ' . $cap : '' ?></span><span class="stat__label">参加人数<?= $cap > 0 ? '（定員）' : '' ?></span></div>
        <div class="stat"><span class="stat__num"><?= $totalCount ?></span><span class="stat__label">申込数（事前<?= $prepaidCount ?>・当日<?= $onsiteCount ?>）</span></div>
        <div class="stat"><span class="stat__num"><?= $attendedCount ?> / <?= $totalCount ?></span><span class="stat__label">出席確認済み</span></div>
        <div class="stat"><span class="stat__num"><?= e(format_amount($collected, $cur0)) ?></span><span class="stat__label">事前入金合計</span></div>
        <div class="stat"><span class="stat__num"><?= e(format_amount($onsiteDue, $cur0)) ?></span><span class="stat__label">当日・未収（受領 <?= $onsiteCollectedCount ?>/<?= $onsiteCount ?>）</span></div>
        <div class="stat"><span class="stat__num"><?= e(format_amount($refunded, $cur0)) ?></span><span class="stat__label">返金合計</span></div>
    </div>

    <?php if ($totalCount === 0): ?>
        <p class="muted">まだ申込はありません。</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>申込日時</th><th>お名前</th><th>メール</th><th>電話</th>
                        <th>人数</th><th>支払方法</th><th>金額</th><th>状態</th><th>出席</th><th>キャンセル / 返金</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $p): ?>
                        <?php
                            $cur = $p['currency'];
                            $isOnsite = ($p['payment_type'] ?? 'prepay') === 'onsite';
                            if ($isOnsite) {
                                $statusHtml = !empty($p['collected'])
                                    ? '<span class="badge badge--ok">受領済み</span>'
                                    : '<span class="badge badge--warn">当日支払い・未収</span>';
                            } elseif ($p['fully_refunded']) {
                                $statusHtml = '<span class="badge badge--danger">全額返金（キャンセル済）</span>';
                            } elseif ($p['amount_refunded'] > 0) {
                                $statusHtml = '<span class="badge badge--warn">一部返金 ' . e(format_amount($p['amount_refunded'], $cur)) . '</span>';
                            } else {
                                $statusHtml = '<span class="badge badge--ok">事前決済済み</span>';
                            }
                            $remaining = $p['amount'] - $p['amount_refunded'];
                        ?>
                        <tr>
                            <td class="muted"><?= e(date('Y-m-d H:i', $p['created'])) ?></td>
                            <td<?= $p['note'] !== '' ? ' title="' . e('備考: ' . $p['note']) . '"' : '' ?>>
                                <?= e($p['name'] !== '' ? $p['name'] : '（未入力）') ?>
                                <?php if ($p['note'] !== ''): ?><span class="muted" style="font-size:.8rem;" title="<?= e($p['note']) ?>">[備考]</span><?php endif; ?>
                            </td>
                            <td><?= e($p['email']) ?></td>
                            <td><?= e($p['phone']) ?></td>
                            <td><?= (int) $p['party_size'] ?> 名</td>
                            <td><?= $isOnsite ? '当日' : '事前' ?></td>
                            <td><?= e(format_amount($p['amount'], $cur)) ?></td>
                            <td><?= $statusHtml ?></td>
                            <td>
                                <?php if (!empty($p['customer_id'])): ?>
                                    <form method="post" action="attend.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                        <input type="hidden" name="event_id" value="<?= e($selectedId) ?>">
                                        <input type="hidden" name="customer_id" value="<?= e($p['customer_id']) ?>">
                                        <?php if (empty($p['attended'])): ?>
                                            <input type="hidden" name="attend" value="1">
                                            <button type="submit" class="btn">出席にする</button>
                                        <?php else: ?>
                                            <input type="hidden" name="attend" value="0">
                                            <span class="badge badge--ok">出席済み</span><br>
                                            <button type="submit" class="btn btn--ghost" style="margin-top:4px;">取消</button>
                                        <?php endif; ?>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isOnsite): ?>
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <form method="post" action="onsite_collect.php">
                                            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                            <input type="hidden" name="event_id" value="<?= e($selectedId) ?>">
                                            <input type="hidden" name="customer_id" value="<?= e($p['customer_id']) ?>">
                                            <?php if (empty($p['collected'])): ?>
                                                <input type="hidden" name="collect" value="1">
                                                <button type="submit" class="btn">受領にする</button>
                                            <?php else: ?>
                                                <input type="hidden" name="collect" value="0">
                                                <button type="submit" class="btn btn--ghost">受領取消</button>
                                            <?php endif; ?>
                                        </form>
                                        <form method="post" action="onsite_cancel.php"
                                              data-confirm="「<?= e($p['name']) ?>」さん（当日支払い）の申込を取り消します。よろしいですか？">
                                            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                            <input type="hidden" name="event_id" value="<?= e($selectedId) ?>">
                                            <input type="hidden" name="customer_id" value="<?= e($p['customer_id']) ?>">
                                            <button type="submit" class="btn btn--danger">取消</button>
                                        </form>
                                    </div>
                                <?php elseif ($p['fully_refunded'] || $remaining <= 0): ?>
                                    <span class="muted">—</span>
                                <?php else: ?>
                                    <form method="post" action="refund.php" class="refund-form"
                                          data-confirm="「<?= e($p['name']) ?>」さんへ返金します。よろしいですか？（Stripe の決済手数料は返金されません）">
                                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                        <input type="hidden" name="event_id" value="<?= e($selectedId) ?>">
                                        <input type="hidden" name="payment_intent" value="<?= e($p['payment_intent']) ?>">
                                        <?php if (strtolower($cur) === 'jpy'): ?>
                                            <input type="number" name="amount" min="1" max="<?= (int) $remaining ?>"
                                                   placeholder="一部¥" title="一部返金する円。空欄なら全額返金。">
                                        <?php else: ?>
                                            <input type="number" name="amount" step="0.01" min="0.01"
                                                   placeholder="一部" title="一部返金額。空欄なら全額返金。">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn--danger">返金</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-top:10px;">返金欄を空欄で実行すると全額返金（＝キャンセル）になります。</p>
        <p class="muted" style="margin-top:4px;">⚠️ 返金しても、Stripe の決済手数料は返金されません（手数料は主催者の負担となります）。全額返金でも手数料分は戻りません。</p>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
