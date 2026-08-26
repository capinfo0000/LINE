<?php

/**
 * 会員のポイント画面：残高・称号・紹介コード・紹介者コード入力・履歴。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();
$memberId = (string) $member['id'];
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ((string) ($_POST['action'] ?? '') === 'referral') {
        // 紹介制度は猶予期間から受付。猶予期間は「有料にするか会員が選ぶ時期」であり、
        // ここで紹介を積めないと「5人紹介したら無料」が誰にも成立しないまま課金が始まる。
        if (!referral_program_open()) {
            $msg = '紹介制度は現在準備中です。制度開始までお待ちください。';
            $msgType = 'ng';
        } else {
            $r = record_referral($memberId, (string) ($_POST['referrer_code'] ?? ''));
            $msg = $r['message'];
            $msgType = $r['ok'] ? 'ok' : 'ng';
        }
    }
}

$balance = member_points($memberId);           // 使えるポイント（残高）
$earned = member_points_earned($memberId);     // 累計獲得（称号の基準・下がらない）
$title = member_title($member);
$history = member_point_history($memberId, 50);
$alreadyReferred = has_referrer($memberId);
$myRefCount = referral_count($memberId);
$myPraise = praise_count($memberId);
$token = csrf_token();

$pageTitle = 'ポイント';
$showLogout = true;
require __DIR__ . '/_header.php';
?>
<p class="muted"><a href="/member/dashboard">← 会員トップへ</a></p>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
    <div class="card__title">あなたのポイント</div>
    <p style="font-size:2rem;font-weight:800;margin:.2rem 0;"><?= number_format($balance) ?> <span style="font-size:1rem;font-weight:600;">pt</span> <span class="muted" style="font-size:.9rem;font-weight:500;">使えるポイント</span></p>
    <p style="margin:.2rem 0;">称号：<span class="badge badge--title"><?= e($title) ?></span>
        <span class="muted" style="font-size:.85rem;">（累計獲得 <?= number_format($earned) ?> pt）</span></p>
    <p class="muted" style="font-size:.82rem;">称号は累計で獲得したポイントで決まり、<strong>ポイントを使っても下がりません</strong>。</p>
    <p class="muted">受けた評価 <?= (int) $myPraise ?> 件／紹介した人数 <?= (int) $myRefCount ?> 名</p>
</div>

<?php if (referral_program_open()): ?>
<div class="card">
    <div class="card__title">紹介であなたのコードを共有</div>
    <p>友人が入会時にあなたのコードを入力すると、<strong>あなたに<?= points_amount('referrer') ?>pt・相手に<?= points_amount('joiner') ?>pt</strong>が入ります。</p>
    <p>あなたの紹介コード：
        <input class="js-select" type="text" value="<?= e(member_referral_code($member)) ?>" readonly style="max-width:200px;font-weight:700;letter-spacing:.08em;">
    </p>
    <p class="muted" style="font-size:.82rem;">※このコードはログインIDとは別です。安心して共有できます。</p>
</div>

<div class="card">
    <div class="card__title">紹介特典（月額会費が無料になります）</div>
    <?php $__min = (int) referral_waiver_min(); ?>
    <?php if (member_waiver_earned($member)): ?>
        <p style="margin:.3rem 0;"><span class="badge badge--info">永久無料</span>
            条件を達成されています。<strong>今後ずっと月額会費は無料</strong>です（あとでご紹介先が減っても戻りません）。</p>
    <?php else: ?>
        <p style="margin:.3rem 0;">ご契約中のご紹介先：<strong><?= (int) referral_waiver_count($memberId) ?></strong> / <?= $__min ?> 名
            <?php if (referral_waiver_count($memberId) >= $__min): ?><span class="badge badge--info">月額0円</span><?php endif; ?></p>
        <p style="margin:.3rem 0;"><?= $__min ?>名ずつご紹介された方：<strong><?= (int) count_qualified_referrals($memberId, referral_waiver_mode() === 'B', $__min) ?></strong> / <?= $__min ?> 名
            <span class="muted" style="font-size:.85rem;">（<?= $__min ?>名になると永久無料）</span></p>
        <p class="muted" style="font-size:.85rem;margin:.3rem 0 0;">
            ご紹介した方が<?= $__min ?>名、月額会費をご契約されると<strong>あなたの月額会費は無料</strong>になります。
            <?= $__min ?>名を下回ると翌月から通常額に戻りますが、その<?= $__min ?>名が<strong>それぞれ<?= $__min ?>名ずつ</strong>ご紹介くださると、<strong>以後ずっと無料</strong>になります。<br>
            ※ご紹介先ご自身が紹介特典で無料になった場合も、ご契約は続いているので人数に含まれます。
        </p>
    <?php endif; ?>
    <p style="margin:.6rem 0 0;"><a href="/pricing">料金と紹介特典の詳しい説明 ›</a></p>
</div>

<div class="card">
    <div class="card__title">紹介コードを入力（入会時に1回のみ）</div>
    <?php if ($alreadyReferred): ?>
        <p class="muted">紹介者は登録済みです。</p>
    <?php else: ?>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="referral">
            <div><label>紹介者のコード</label>
                <input type="text" name="referrer_code" placeholder="例: 8F3KQ9MN" maxlength="16" style="max-width:220px;text-transform:uppercase;"></div>
            <div><button type="submit" class="btn">登録する</button></div>
        </form>
        <p class="muted" style="margin-top:6px;">※<strong>登録は1回だけです。あとから変更・取り消しはできません</strong>ので、コードをよくご確認ください。</p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card">
    <div class="card__title">紹介制度について</div>
    <p class="muted" style="margin:0;">紹介コード・紹介特典は、<strong>会員数が上限に達した時点で公開</strong>予定です。今しばらくお待ちください。</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card__title">ポイント履歴</div>
    <?php if ($history === []): ?>
        <p class="muted">まだ履歴がありません。</p>
    <?php else: ?>
        <div class="table-wrap">
        <table style="width:100%;min-width:480px;border-collapse:collapse;font-size:.88rem;">
            <tr style="text-align:left;border-bottom:1px solid var(--border);"><th style="padding:6px;">日時</th><th>内容</th><th style="text-align:right;">増減</th></tr>
            <?php foreach ($history as $h): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:6px;"><?= e(date('Y-m-d H:i', (int) $h['created_at'])) ?></td>
                    <td><?= e(point_reason_label((string) $h['reason'])) ?></td>
                    <td style="text-align:right;font-weight:700;color:<?= (int) $h['delta'] >= 0 ? '#166534' : '#991b1b' ?>;"><?= (int) $h['delta'] >= 0 ? '+' : '' ?><?= (int) $h['delta'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
