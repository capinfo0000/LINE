<?php

/**
 * 会員詳細・操作（承認・入金状況・ID/PW再発行・ステータス変更）。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$id = (string) ($_GET['id'] ?? ($_POST['id'] ?? ''));
$member = $id !== '' ? find_member_by_id($id) : null;
if ($member === null) {
    http_response_code(404);
    exit('会員が見つかりません。');
}
$msg = '';
$msgType = 'ok';
$subLink = '';   // 発行した決済リンク（コピー用に画面へ出す）

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    switch ($action) {
        case 'adfree_grant':
            // 広告非表示を付与する（提携・お礼・購入の手入力など）。
            // 期限に足す形なので、押すたびに延びる。
            $d = max(1, min(3650, (int) ($_POST['days'] ?? 30)));
            $until = extend_ads_free($id, $d, 'admin');
            $msg = $until > 0
                ? '広告非表示を ' . $d . '日 付与しました（' . date('Y/n/j', $until) . ' まで）。'
                : '対象の会員が見つかりませんでした。';
            $msgType = $until > 0 ? 'ok' : 'ng';
            break;
        case 'adfree_clear':
            clear_ads_free($id);
            $msg = '広告非表示を解除しました。';
            break;
        case 'unlock_intro':
            mark_intro_submitted($id);
            audit_log('admin.intro_unlocked', ['member' => $id]);
            $msg = '自己紹介ロックを解除しました（「さがす」が使えます）。';
            break;
        case 'relock_intro':
            // 送信記録と免除の両方を落とさないと、免除中の会員をロックし直せない。
            db()->prepare('UPDATE members SET intro_submitted_at = NULL WHERE id = ?')->execute([$id]);
            intro_gate_unexempt($id);
            audit_log('admin.intro_relocked', ['member' => $id]);
            $msg = '自己紹介ロックを再設定しました。';
            break;
        case 'delete_member':
            // 会員を完全削除（元に戻せない）。取り返しがつかないため管理者テナント限定。
            // ※ サンプル会員の一括操作（dashboard.php）と同じ基準に揃えている。
            if ((int) ($tenant['is_admin'] ?? 0) !== 1) {
                $msg = 'この操作にはプラットフォーム管理者権限が必要です。会員の削除は管理者にご依頼ください。';
                $msgType = 'ng';
                break;
            }
            if (admin_delete_member($id)) {
                header('Location: members?msg=' . rawurlencode('会員を削除しました。'));
                exit;
            }
            $msg = '会員が見つかりませんでした。';
            $msgType = 'ng';
            break;
        case 'reissue':
            $msg = admin_reissue_credentials($id) ? 'ID/PWを再発行し配布しました。' : 'ID/PWを再発行しました（配布経路が無いため送信はスキップ）。';
            break;
        case 'status':
            if (admin_set_member_status($id, (string) ($_POST['status'] ?? ''))) {
                $msg = 'ステータスを変更しました。';
            } else {
                $msg = 'ステータスを変更できませんでした。一覧から選び直してもう一度お試しください。';
                $msgType = 'ng';
            }
            break;
        case 'points_adjust':
            $delta = (int) ($_POST['delta'] ?? 0);
            $note = (string) ($_POST['note'] ?? '');
            if ($delta !== 0) {
                add_points($id, $delta, 'admin_adjust', null, $note);
                audit_log('admin.points_adjust', ['member' => $id, 'delta' => $delta]);
                $msg = 'ポイントを調整しました（' . ($delta > 0 ? '+' : '') . $delta . 'pt）。';
            } else {
                // 0 のときは何も起きないのに無反応だと、押したのに変わらない理由が分からない。
                $msg = '増減するポイント数を入力してください（0 では変更されません）。';
                $msgType = 'ng';
            }
            break;
        case 'resolve_report':
            if (resolve_report((int) ($_POST['report_id'] ?? 0), (int) ($_POST['penalty'] ?? 0))) {
                $msg = '通報を処理しました。';
            } else {
                $msg = '対象の通報が見つかりませんでした（すでに処理済みの可能性があります）。';
                $msgType = 'ng';
            }
            break;
        case 'set_title':
            $r = set_member_title($id, (string) ($_POST['title'] ?? ''));
            $msg = $r['message'];
            $msgType = $r['ok'] ? 'ok' : 'ng';
            break;
        case 'sub_link':
            // 月額会費の決済リンクを発行して、必要なら会員へ送る。
            // 会員自身の申込画面（member/subscribe.php）と同じ組み立てを使うので、
            // 猶予期間中なら初回請求は課金開始日になり、紹介の条件を満たしていれば
            // 100%OFFクーポンも自動で付く。
            if (!member_can_subscribe_now($member)) {
                $msg = billing_reached_at() === null
                    ? 'まだ課金制度が始まっていないため、決済リンクは発行できません。'
                    : 'この会員はすでに月額会費をご契約中か、有効な会員ではありません。';
                $msgType = 'ng';
                break;
            }
            try {
                $subLink = create_subscription_checkout(
                    $member,
                    base_url() . '/member/dashboard?msg=' . rawurlencode('月額登録が完了しました。') . '&type=ok',
                    base_url() . '/member/billing'
                );
                audit_log('admin.sub_link', ['member' => $id]);
                $deliver = (string) ($_POST['deliver'] ?? 'none');
                $sent = admin_send_subscription_link($member, $subLink, $deliver);
                $msg = '決済リンクを発行しました（24時間有効）。' . $sent;
            } catch (\Throwable $e) {
                error_log('admin sub_link error: ' . $e->getMessage());
                $msg = '決済リンクを発行できませんでした：' . $e->getMessage();
                $msgType = 'ng';
            }
            break;
        case 'waiver_revoke':
            // 紹介特典の無料を剥奪する（不正な紹介が判明したときなど）。
            // 取り消しの影響が大きいので、会員の削除と同じく管理者テナント限定にする。
            if ((int) ($tenant['is_admin'] ?? 0) !== 1) {
                audit_log('authz.admin_deny', ['tenant' => $tenant['id'], 'path' => 'admin/member_detail.waiver_revoke']);
                $msg = 'この操作にはプラットフォーム管理者権限が必要です。';
                $msgType = 'ng';
                break;
            }
            try {
                init_stripe();
                $msg = revoke_member_waiver($member)
                    ? '紹介特典の無料を取り消しました（次回のご請求から通常額になります）。'
                    : 'この会員は無料化されていません。';
            } catch (\Throwable $e) {
                error_log('admin waiver_revoke error: ' . $e->getMessage());
                $msg = '取り消しに失敗しました：' . $e->getMessage();
                $msgType = 'ng';
            }
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
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>
<p><a href="members">← 会員一覧</a></p>

<div class="card">
    <div class="card__title">基本情報</div>
    <p>ログインID：<code><?= e($member['login_id']) ?></code>　ステータス：<strong><?= e($member['status']) ?></strong>
       <?= (int) $member['must_change_pw'] === 1 ? '<span class="muted">(要PW変更)</span>' : '' ?></p>
    <p>名前：<?= e($profile['name_text'] ?: ($member['display_name'] ?? '-')) ?>　メール：<?= e($member['email'] ?? '-') ?></p>
    <p>LINE：<?= e($member['line_user_id'] ?? '-') ?>　入会日：<?= $member['joined_at'] ? e(date('Y-m-d', (int) $member['joined_at'])) : '-' ?></p>
    <p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        共有URL：<code id="shareUrl" style="word-break:break-all;"><?= e(member_public_url($member)) ?></code>
        <button type="button" class="btn btn--ghost" data-copy-target="shareUrl"
                style="width:auto;padding:4px 12px;font-size:.82rem;">コピー</button>
    </p>
    <?php
    // 表示は実効プラン（member_plan）に合わせる。列の値だけを見ると、
    // 会費を払っている会員が「ベーシック」と出てしまう。
    // 切り替えボタンが操作するのは列の値なので、そちらは別に持つ。
    $curPlan = member_plan($member);
    $storedPlan = ($member['plan'] ?? 'basic') === 'premium' ? 'premium' : 'basic';
    ?>
    <p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;">
        プラン：<span class="badge badge--<?= $curPlan === 'premium' ? 'info' : 'mute' ?>"><?= e(plan_label($curPlan)) ?></span><?php if (!billing_started()): ?> <span class="muted" style="font-size:.82rem;">（無料フェーズ中は全員プレミアム相当）</span>
        <?php elseif ($storedPlan !== 'premium' && $curPlan === 'premium'): ?> <span class="muted" style="font-size:.82rem;">（会費のお支払いにより全機能）</span><?php endif; ?>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="set_plan">
            <input type="hidden" name="plan" value="<?= $storedPlan === 'premium' ? 'basic' : 'premium' ?>">
            <button class="btn btn--sm btn--ghost" data-confirm="プランの設定を<?= $storedPlan === 'premium' ? 'ベーシック' : 'プレミアム' ?>に変更しますか？"><?= $storedPlan === 'premium' ? '設定をベーシックに戻す' : '設定をプレミアムにする' ?></button>
        </form>
        <span class="muted" style="font-size:.8rem;">※無料フェーズ中は全員プレミアム相当（制限なし）</span>
    </p>

    <?php $__af = (int) ($member['ads_free_until'] ?? 0); ?>
    <p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">
        広告：<span class="badge badge--<?= member_ads_free($member) ? 'info' : 'mute' ?>"><?= member_ads_free($member) ? '非表示' : '表示' ?></span>
        <?php if ($__af > time()): ?>
            <span class="muted" style="font-size:.82rem;"><?= e(date('Y/n/j', $__af)) ?> まで</span>
        <?php endif; ?>
        <form method="post" style="display:inline-flex;gap:6px;align-items:center;">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="adfree_grant">
            <input type="number" name="days" value="30" min="1" max="3650" style="width:5.5em;margin:0;">
            <button class="btn btn--sm btn--ghost">日ぶん付与</button>
        </form>
        <?php if ($__af > 0): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="adfree_clear">
                <button class="btn btn--sm btn--ghost" data-confirm="広告非表示を解除しますか？">解除</button>
            </form>
        <?php endif; ?>
        <span class="muted" style="font-size:.8rem;">※付与は今の期限に足されます</span>
    </p>
</div>

<?php
$__canSub   = member_can_subscribe_now($member);
$__subLabel = ['active'=>'有効','past_due'=>'お支払い確認中','canceled'=>'解約済み','unpaid'=>'未払い'][(string) ($member['subscription_status'] ?? '')]
              ?? ((string) ($member['subscription_status'] ?? '') !== '' ? (string) $member['subscription_status'] : '未登録');
$__waived   = (int) ($member['subscription_waived'] ?? 0) === 1;
$__earned   = member_waiver_earned($member);
$__min      = (int) referral_waiver_min();
$__refNow   = referral_waiver_count($id);
$__refDeep  = count_qualified_referrals($id, referral_waiver_mode() === 'B', $__min);
$__trialEnd = subscription_trial_end();
?>
<div class="card">
    <div class="card__title">月額会費</div>
    <p style="margin:.3rem 0;">状態：<strong><?= e($__subLabel) ?></strong>
        <?php if ($__earned): ?><span class="badge badge--info">永久無料</span>
        <?php elseif ($__waived): ?><span class="badge badge--info">紹介特典で無料</span><?php endif; ?>
    </p>
    <p class="hint" style="margin:0 0 10px;">
        ご契約中の紹介先 <strong><?= $__refNow ?></strong> / <?= $__min ?> 名（<?= $__min ?>名以上で無料）　·　
        <?= $__min ?>名ずつ紹介した紹介先 <strong><?= $__refDeep ?></strong> / <?= $__min ?> 名（<?= $__min ?>名以上で永久無料）
    </p>

    <?php if ($subLink !== ''): ?>
        <div style="border-top:1px solid var(--border);margin:10px 0;padding-top:10px;">
            <label style="margin-top:0;">発行した決済リンク（24時間で失効します）</label>
            <input class="js-select" type="text" value="<?= e($subLink) ?>" readonly style="width:100%;font-size:.82rem;">
            <p class="hint" style="margin:6px 0 0;">クリックで全選択されます。会員へお渡しください。</p>
        </div>
    <?php endif; ?>

    <?php if ($__canSub): ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
        <input type="hidden" name="action" value="sub_link">
        <div><label>送り方</label>
            <select name="deliver" style="min-width:11em;">
                <option value="none">リンクを表示するだけ</option>
                <option value="line"<?= (string) ($member['line_user_id'] ?? '') === '' ? ' disabled' : '' ?>>LINEで送る<?= (string) ($member['line_user_id'] ?? '') === '' ? '（未連携）' : '' ?></option>
                <option value="mail"<?= (string) ($member['email'] ?? '') === '' ? ' disabled' : '' ?>>メールで送る<?= (string) ($member['email'] ?? '') === '' ? '（未登録）' : '' ?></option>
                <option value="both">LINEとメールの両方</option>
            </select>
        </div>
        <div><button class="btn" data-confirm="月額会費の決済リンクを発行します。よろしいですか？">決済リンクを発行</button></div>
    </form>
    <p class="hint" style="margin:8px 0 0;">
        会員自身の申込画面と同じ条件で作られます。
        <?php if (member_qualifies_for_waiver($member)): ?>
            <strong>この会員は紹介の条件を満たしているため、100%OFFクーポンが付きます（初回から無料）。</strong>
        <?php elseif ($__trialEnd !== null): ?>
            現在は猶予期間のため、<strong>初回のご請求は<?= e(date('n月j日', $__trialEnd)) ?>から</strong>になります。
        <?php endif; ?>
    </p>
    <?php else: ?>
        <p class="hint" style="margin:0;">
            <?php if (billing_reached_at() === null): ?>
                まだ課金制度が始まっていないため、決済リンクは発行できません（会員数が<?= (int) billing_free_limit() ?>名を超えると発行できるようになります）。
            <?php elseif ((string) ($member['subscription_status'] ?? '') === 'active'): ?>
                すでに月額会費をご契約中のため、新しい決済リンクは発行しません。
            <?php else: ?>
                有効な会員ではないため、決済リンクは発行できません。
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($__waived || $__earned): ?>
    <div style="border-top:1px solid var(--border);margin-top:12px;padding-top:12px;">
        <label style="margin-top:0;">紹介特典の取り消し</label>
        <p class="hint" style="margin:0 0 8px;">
            不正な紹介（同一人物の複数アカウントなど）が判明した場合に、無料を取り消します。
            Stripe の割引を外し<?= $__earned ? '、永久無料の資格も取り消し' : '' ?>ます。通常額になるのは<strong>次回のご請求から</strong>です。<br>
            ※通常の無料は、紹介先が<?= $__min ?>名を割れば自動で外れます。この操作が要るのは永久無料か、待たずに外したいときだけです。
        </p>
        <?php if ((int) ($tenant['is_admin'] ?? 0) === 1): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
            <button class="btn btn--ghost" name="action" value="waiver_revoke"
                    data-confirm="この会員の紹介特典（月額無料）を取り消します。よろしいですか？">紹介特典を取り消す</button>
        </form>
        <?php else: ?>
        <p class="hint" style="margin:0;">取り消しはプラットフォーム管理者のみが行えます。</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
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
    <p><strong style="font-size:1.3rem;"><?= number_format($pBalance) ?></strong> pt <span class="muted" style="font-size:.82rem;">使えるポイント</span>　<span class="badge badge--title"><?= e(member_title($member)) ?></span> <span class="muted" style="font-size:.82rem;">（累計獲得 <?= number_format($pEarned) ?> pt）</span>
       <span class="muted">／ 受けた評価 <?= (int) praise_count($id) ?> 件・通報 <?= (int) report_count($id) ?> 件・紹介 <?= (int) referral_count($id) ?> 名</span></p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:8px;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="points_adjust">
        <div><label>調整（±）</label><input type="number" name="delta" value="0" style="max-width:110px;"></div>
        <div style="flex:1;min-width:160px;"><label>メモ（任意）</label><input type="text" name="note" maxlength="200"></div>
        <div><button class="btn">ポイント調整</button></div>
    </form>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="action" value="set_title">
        <div style="flex:1;min-width:200px;">
            <label>称号</label>
            <select name="title">
                <option value="">自動（累計ポイントで決まる）</option>
                <?php foreach (assignable_titles() as $t): ?>
                    <option value="<?= e($t) ?>"<?= (string) ($member['title_override'] ?? '') === $t ? ' selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><button class="btn">称号を設定</button></div>
        <p class="hint" style="flex-basis:100%;margin:2px 0 0;">手動で設定すると、以後ポイントが増えても称号は変わりません。「自動」に戻すとポイント連動に戻ります。</p>
    </form>
</div>

<?php if ($myReports !== []): ?>
<div class="card">
    <div class="card__title">この会員への通報（未処理 <?= count($myReports) ?> 件）</div>
    <?php foreach ($myReports as $rp): ?>
        <div style="border-bottom:1px solid var(--border);padding:8px 0;">
            <div class="muted" style="font-size:.82rem;"><?= e(date('Y-m-d H:i', (int) $rp['created_at'])) ?>　通報者: <?= e($rp['rater_login'] ?? '-') ?></div>
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
    <p><img src="member_photo?id=<?= e($id) ?>" alt="" style="max-width:160px;border-radius:10px;"></p>
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
    $__exempt = member_intro_exempt($member);
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
            <?php elseif ($__introDone): ?>
                <strong style="color:#166534;">解除済み</strong>（自己紹介の送信を確認しました）。
            <?php else: ?>
                <strong style="color:#166534;">解除済み</strong>（ロックをOFFにした時点で在籍していたため免除。自己紹介の送信は確認していません）。
            <?php endif; ?>
        </p>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
            <?php if ($__introDone || $__exempt): ?>
                <button class="btn btn--ghost" name="action" value="relock_intro"
                        data-confirm="この会員に自己紹介ロックを再設定します（公式LINEに自己紹介を送るまで「さがす」が使えなくなります）。よろしいですか？">ロックを再設定</button>
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
    <?php if ((int) ($tenant['is_admin'] ?? 0) === 1): ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
        <button class="btn btn--danger" name="action" value="delete_member"
                data-confirm="この会員を完全に削除します。元に戻せません。よろしいですか？">この会員を削除する</button>
    </form>
    <?php else: ?>
    <p class="hint" style="margin:0;">削除はプラットフォーム管理者のみが行えます。必要な場合は管理者にご依頼ください。</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
