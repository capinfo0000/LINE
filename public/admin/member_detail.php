<?php

/**
 * 会員詳細・操作（承認・入金状況・ID/PW再発行・ステータス変更）。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$id = (string) ($_GET['id'] ?? ($_POST['id'] ?? ''));
$member = $id !== '' ? find_member_by_id($id) : null;
if ($member === null) {
    http_response_code(404);
    exit('会員が見つかりません。');
}
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    switch ($action) {
        case 'unlock_intro':
            mark_intro_submitted($id);
            audit_log('admin.intro_unlocked', ['member' => $id]);
            $msg = '自己紹介ロックを解除しました（「さがす」が使えます）。';
            break;
        case 'relock_intro':
            db()->prepare('UPDATE members SET intro_submitted_at = NULL WHERE id = ?')->execute([$id]);
            audit_log('admin.intro_relocked', ['member' => $id]);
            $msg = '自己紹介ロックを再設定しました。';
            break;
        case 'delete_member':
            // 会員を完全削除（元に戻せない）。削除後は一覧へ戻す。
            if (admin_delete_member($id)) {
                header('Location: members.php?msg=' . rawurlencode('会員を削除しました。'));
                exit;
            }
            $msg = '会員が見つかりませんでした。';
            break;
        case 'reissue':
            $msg = admin_reissue_credentials($id) ? 'ID/PWを再発行し配布しました。' : 'ID/PWを再発行しました（配布経路が無いため送信はスキップ）。';
            break;
        case 'status':
            admin_set_member_status($id, (string) ($_POST['status'] ?? ''));
            $msg = 'ステータスを変更しました。';
            break;
        case 'points_adjust':
            $delta = (int) ($_POST['delta'] ?? 0);
            $note = (string) ($_POST['note'] ?? '');
            if ($delta !== 0) {
                add_points($id, $delta, 'admin_adjust', null, $note);
                audit_log('admin.points_adjust', ['member' => $id, 'delta' => $delta]);
                $msg = 'ポイントを調整しました（' . ($delta > 0 ? '+' : '') . $delta . 'pt）。';
            }
            break;
        case 'resolve_report':
            resolve_report((int) ($_POST['report_id'] ?? 0), (int) ($_POST['penalty'] ?? 0));
            $msg = '通報を処理しました。';
            break;
        case 'set_plan':
            $plan = (string) ($_POST['plan'] ?? 'basic');
            set_member_plan($id, $plan);
            audit_log('admin.set_plan', ['member' => $id, 'plan' => $plan]);
            $msg = 'プランを' . plan_label($plan === 'premium' ? 'premium' : 'basic') . 'に変更しました。';
            break;
    }
    $member = find_member_by_id($id);
}

$profile = get_profile($id);
$payments = member_payments($id);
$token = csrf_token();
$pageTitle = '会員詳細';
$pageSub = $member['login_id'];
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash flash--ok"><?= e($msg) ?></div><?php endif; ?>
<p><a href="members.php">← 会員一覧</a></p>

<div class="card">
    <div class="card__title">基本情報</div>
    <p>ログインID：<code><?= e($member['login_id']) ?></code>　ステータス：<strong><?= e($member['status']) ?></strong>
       <?= (int) $member['must_change_pw'] === 1 ? '<span class="muted">(要PW変更)</span>' : '' ?></p>
    <p>名前：<?= e($profile['name_text'] ?: ($member['display_name'] ?? '-')) ?>　メール：<?= e($member['email'] ?? '-') ?></p>
    <p>LINE：<?= e($member['line_user_id'] ?? '-') ?>　入会日：<?= $member['joined_at'] ? e(date('Y-m-d', (int) $member['joined_at'])) : '-' ?></p>
    <?php $curPlan = ($member['plan'] ?? 'basic') === 'premium' ? 'premium' : 'basic'; ?>
    <p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;">
        プラン：<span class="badge" style="background:<?= $curPlan === 'premium' ? '#eef2ff;color:#3730a3' : '#f1f5f9;color:#475569' ?>;"><?= e(plan_label($curPlan)) ?></span>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="set_plan">
            <input type="hidden" name="plan" value="<?= $curPlan === 'premium' ? 'basic' : 'premium' ?>">
            <button class="btn btn--sm btn--ghost" data-confirm="プランを<?= $curPlan === 'premium' ? 'ベーシック' : 'プレミアム' ?>に変更しますか？"><?= $curPlan === 'premium' ? 'ベーシックに戻す' : 'プレミアムにする' ?></button>
        </form>
        <span class="muted" style="font-size:.8rem;">※無料フェーズ中は全員プレミアム相当（制限なし）</span>
    </p>
</div>

<div class="card">
    <div class="card__title">入金状況</div>
    <?php if ($payments === []): ?>
        <p class="muted">入金記録はありません。</p>
    <?php else: foreach ($payments as $p): ?>
        <p><?= e(format_amount((int) $p['amount'], (string) $p['currency'])) ?>　<?= e($p['status']) ?>
           <?= $p['paid_at'] ? e(date('Y-m-d H:i', (int) $p['paid_at'])) : '' ?>
           <span class="muted"><?= e($p['stripe_checkout_session_id']) ?></span></p>
    <?php endforeach; endif; ?>
</div>

<?php
$pBalance = member_points($id);
$pEarned = member_points_earned($id);
$myReports = db()->prepare("SELECT e.id, e.note, e.created_at, r.login_id AS rater_login FROM member_evaluations e LEFT JOIN members r ON r.id = e.rater_id WHERE e.target_id = ? AND e.kind = 'report' AND e.handled = 0 ORDER BY e.id DESC");
$myReports->execute([$id]);
$myReports = $myReports->fetchAll();
?>
<div class="card">
    <div class="card__title">ポイント・称号</div>
    <p><strong style="font-size:1.3rem;"><?= number_format($pBalance) ?></strong> pt <span class="muted" style="font-size:.82rem;">使えるポイント</span>　<span class="badge badge--title"><?= e(points_title($pEarned)) ?></span> <span class="muted" style="font-size:.82rem;">（累計獲得 <?= number_format($pEarned) ?> pt）</span>
       <span class="muted">／ 受けた評価 <?= (int) praise_count($id) ?> 件・通報 <?= (int) report_count($id) ?> 件・紹介 <?= (int) referral_count($id) ?> 名</span></p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:8px;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="points_adjust">
        <div><label>調整（±）</label><input type="number" name="delta" value="0" style="max-width:110px;"></div>
        <div style="flex:1;min-width:160px;"><label>メモ（任意）</label><input type="text" name="note" maxlength="200"></div>
        <div><button class="btn">ポイント調整</button></div>
    </form>
</div>

<?php if ($myReports !== []): ?>
<div class="card">
    <div class="card__title">この会員への通報（未処理 <?= count($myReports) ?> 件）</div>
    <?php foreach ($myReports as $rp): ?>
        <div style="border-bottom:1px solid var(--border);padding:8px 0;">
            <div class="muted" style="font-size:.82rem;"><?= e(date('Y-m-d H:i', (int) $rp['created_at'] + 9 * 3600)) ?>　通報者: <?= e($rp['rater_login'] ?? '-') ?></div>
            <?php if (($rp['note'] ?? '') !== ''): ?><p style="margin:4px 0;white-space:pre-wrap;"><?= e($rp['note']) ?></p><?php endif; ?>
            <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
                <form method="post" style="display:flex;gap:8px;align-items:end;margin:0;" data-confirm="通報を確認し、対象会員を減点して処理します。よろしいですか？">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="resolve_report">
                    <input type="hidden" name="report_id" value="<?= (int) $rp['id'] ?>">
                    <div><label>減点</label><input type="number" name="penalty" value="<?= points_amount('report_penalty') ?>" min="0" style="max-width:100px;"></div>
                    <button class="btn">減点して処理</button>
                </form>
                <form method="post" style="margin:0;" data-confirm="この通報を減点なしで処理（却下）します。よろしいですか？">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="resolve_report">
                    <input type="hidden" name="report_id" value="<?= (int) $rp['id'] ?>"><input type="hidden" name="penalty" value="0">
                    <button class="btn btn--ghost">却下（減点なし）</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($profile['photo_path'])): ?>
<div class="card">
    <div class="card__title">顔写真</div>
    <p><img src="member_photo.php?id=<?= e($id) ?>" alt="" style="max-width:160px;border-radius:10px;"></p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card__title">操作</div>
    <form method="post" style="margin-bottom:12px;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
        <button class="btn" name="action" value="reissue" data-confirm="ID/PWを再発行して配布します。よろしいですか？">ID/PWを再発行して配布</button>
    </form>
    <?php
    $__introDone = member_intro_submitted($member);
    $__gated = member_needs_intro($member);
    ?>
    <div style="border-top:1px solid var(--border);margin:12px 0;padding-top:12px;">
        <label style="margin-top:0;">自己紹介ロック（「さがす」の閲覧）</label>
        <p class="hint" style="margin:0 0 8px;">
            <?php if (!intro_gate_enabled()): ?>
                現在ロック機能はOFFです（全員が「さがす」を利用できます）。
            <?php elseif ((string) ($member['line_user_id'] ?? '') === ''): ?>
                この会員はLINE未連携のため、ロックの対象外です。
            <?php elseif ($__gated): ?>
                <strong style="color:var(--dng);">ロック中</strong>（公式LINEへの自己紹介が未確認）。
                会員が送信済みなのに解除されない場合は、下のボタンで手動解除できます。
            <?php else: ?>
                <strong style="color:#166534;">解除済み</strong>（自己紹介の送信を確認しました）。
            <?php endif; ?>
        </p>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
            <?php if ($__introDone): ?>
                <button class="btn btn--ghost" name="action" value="relock_intro"
                        data-confirm="この会員に自己紹介ロックを再設定します。よろしいですか？">ロックを再設定</button>
            <?php else: ?>
                <button class="btn" name="action" value="unlock_intro"
                        data-confirm="この会員の自己紹介ロックを解除します（「さがす」が使えるようになります）。よろしいですか？">ロックを手動解除</button>
            <?php endif; ?>
        </form>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
        <label>ステータス変更</label>
        <select name="status">
            <?php foreach (['active' => '有効', 'suspended' => '停止', 'cancelled' => '退会', 'pending_payment' => '未入金'] as $v => $l): ?>
                <option value="<?= e($v) ?>"<?= $member['status'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--ghost" name="action" value="status">変更</button>
    </form>
</div>

<div class="card" style="border-color:var(--dng-bg);">
    <div class="card__title" style="color:var(--dng);">会員の削除</div>
    <p class="hint" style="margin:0 0 10px;">
        この会員を<strong>完全に削除</strong>します（元に戻せません）。プロフィール・写真・タグ・ポイント・評価・足あともすべて削除され、
        LINE連絡先の紐付けは解除されます（連絡先自体は残ります）。<br>
        一時的に利用を止めるだけなら、上の「ステータス変更」で<strong>停止</strong>または<strong>退会</strong>にしてください。
    </p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
        <button class="btn btn--danger" name="action" value="delete_member"
                data-confirm="この会員を完全に削除します。元に戻せません。よろしいですか？">この会員を削除する</button>
    </form>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
