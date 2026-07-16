<?php

/**
 * イベント管理画面（ログイン中テナント専用）。
 * ログインした主催者が自分のイベントを登録・編集・削除できる。
 * イベントは DB（events テーブル）にテナント単位で保存する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();

$events = tenant_events($tenant['id']);

// 編集対象（?edit=ID）。新規のときは空のひな形。他テナントのものは編集不可。
$editId = (string) ($_GET['edit'] ?? '');
$editing = $editId !== '' ? find_event($editId) : null;
if ($editing !== null && $editing['tenant_id'] !== $tenant['id']) {
    $editing = null;
}
$form = $editing ?? [
    'id' => '', 'name' => '', 'description' => '', 'date' => '',
    'place' => '', 'amount' => '', 'currency' => 'jpy', 'capacity' => '',
    'amount_onsite' => '', 'allow_prepay' => true, 'allow_onsite' => false,
];

$flash = (string) ($_GET['msg'] ?? '');
$flashType = (string) ($_GET['type'] ?? '');
$token = csrf_token();

$pageTitle = 'イベント管理';
require __DIR__ . '/_app_header.php';
?>
<?php if (!stripe_ready_for_tenant($tenant)): ?>
    <div class="flash flash--ng">⚠️ Stripe 未設定です。クレジットカード決済（事前決済）を使うには <a href="stripe.php">Stripe設定</a> から鍵を登録してください（当日支払い・現金のみなら設定不要）。</div>
<?php endif; ?>

<?php if ($flash !== ''): ?>
    <div class="flash <?= $flashType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card__title"><?= $editing ? 'イベントを編集' : 'イベントを新規登録' ?></div>
    <form method="post" action="event_save.php">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <input type="hidden" name="id" value="<?= e((string) $form['id']) ?>">

        <label>イベント名 <span class="req">必須</span></label>
        <input type="text" name="name" required maxlength="100" value="<?= e((string) $form['name']) ?>" placeholder="夏のBBQ大会 2026">

        <label>説明</label>
        <textarea name="description" maxlength="500" placeholder="食材・ドリンク込み。雨天時は..."><?= e((string) $form['description']) ?></textarea>

        <div class="row">
            <div>
                <label>日時 <span class="req">必須</span></label>
                <input type="datetime-local" name="date" required value="<?= e(datetime_local_value((string) $form['date'])) ?>">
            </div>
            <div>
                <label>場所 <span class="req">必須</span></label>
                <input type="text" name="place" required maxlength="100" value="<?= e((string) $form['place']) ?>" placeholder="多摩川河川敷">
            </div>
        </div>

        <div class="row">
            <div>
                <label>事前決済の参加費（1名・円） <span class="req">必須</span></label>
                <input type="number" name="amount" required min="0" step="1" value="<?= e((string) $form['amount']) ?>" placeholder="3000">
            </div>
            <div>
                <label>当日支払いの参加費（1名）<span class="hint">空欄なら事前と同額</span></label>
                <input type="number" name="amount_onsite" min="0" step="1" value="<?= e((string) $form['amount_onsite']) ?>" placeholder="4000">
            </div>
        </div>

        <div class="row">
            <div>
                <label>通貨</label>
                <input type="text" value="日本円（JPY）" readonly>
            </div>
            <div>
                <label>定員目安（申込人数の上限にも使用）</label>
                <input type="number" name="capacity" min="0" step="1" value="<?= e((string) $form['capacity']) ?>" placeholder="20">
            </div>
        </div>

        <label>受け付ける支払い方法</label>
        <div style="display:flex; gap:20px; margin-top:4px;">
            <label style="font-weight:400; margin:0;"><input type="checkbox" name="allow_prepay" value="1" <?= !empty($form['allow_prepay']) ? 'checked' : '' ?> style="width:auto;"> 事前決済（クレジットカードで前払い）</label>
            <label style="font-weight:400; margin:0;"><input type="checkbox" name="allow_onsite" value="1" <?= !empty($form['allow_onsite']) ? 'checked' : '' ?> style="width:auto;"> 当日支払い（現地で集金）</label>
        </div>

        <p style="margin-top:18px;">
            <button type="submit" class="btn"><?= $editing ? '更新する' : '登録する' ?></button>
            <?php if ($editing): ?><a class="btn btn--ghost" href="events.php">新規登録に切り替え</a><?php endif; ?>
        </p>
    </form>
</div>

<div class="card">
    <div class="card__title">登録済みイベント（<?= count($events) ?>件）</div>
    <?php if (empty($events)): ?>
        <p class="muted">まだイベントがありません。上のフォームから登録してください。</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>イベント名</th><th>日時</th><th>場所</th><th>参加費</th><th>申込リンク</th><th>操作</th></tr></thead>
                <tbody>
                    <?php foreach ($events as $ev): ?>
                        <?php $applyUrl = base_url() . '/apply.php?event_id=' . urlencode($ev['id']); ?>
                        <tr>
                            <td><strong><?= e($ev['name'] ?? '') ?></strong></td>
                            <td class="muted"><?= e($ev['date'] ?? '') ?></td>
                            <td class="muted"><?= e($ev['place'] ?? '') ?></td>
                            <td>
                                事前 <?= e(format_amount((int) ($ev['amount'] ?? 0), $ev['currency'] ?? 'jpy')) ?>
                                <?php if (!empty($ev['allow_onsite'])): ?><br><span class="muted">当日 <?= e(format_amount((int) ($ev['amount_onsite'] ?? 0), $ev['currency'] ?? 'jpy')) ?></span><?php endif; ?>
                            </td>
                            <td><input type="text" class="js-select" readonly value="<?= e($applyUrl) ?>" style="width:200px; font-size:.8rem; padding:5px 8px;"></td>
                            <td>
                                <div style="display:flex; gap:8px; align-items:center;">
                                    <a class="btn btn--ghost" href="events.php?edit=<?= e($ev['id']) ?>">編集</a>
                                    <form method="post" action="event_delete.php"
                                          data-confirm="「<?= e($ev['name'] ?? '') ?>」を削除します。よろしいですか？（過去の申込・決済データは Stripe に残ります）">
                                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                        <input type="hidden" name="id" value="<?= e($ev['id']) ?>">
                                        <button type="submit" class="btn btn--danger">削除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
