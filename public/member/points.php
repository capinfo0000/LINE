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
        // 紹介制度は課金開始後のみ受付（未公開フェーズの前倒し実行を防ぐ）。
        if (!billing_started()) {
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

<?php if (billing_started()): ?>
<div class="card">
    <div class="card__title">紹介であなたのコードを共有</div>
    <p>友人が入会時にあなたのコードを入力すると、<strong>あなたに<?= points_amount('referrer') ?>pt・相手に<?= points_amount('joiner') ?>pt</strong>が入ります。</p>
    <p>あなたの紹介コード：
        <input class="js-select" type="text" value="<?= e(member_referral_code($member)) ?>" readonly style="max-width:200px;font-weight:700;letter-spacing:.08em;">
    </p>
    <p class="muted" style="font-size:.82rem;">※このコードはログインIDとは別です。安心して共有できます。</p>
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
        <p class="muted" style="margin-top:6px;">※登録は1回だけ・あとから変更できません。</p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card">
    <div class="card__title">紹介制度について</div>
    <p class="muted" style="margin:0;">紹介コード・紹介特典は、<strong>本サービスの制度開始（課金開始）時に公開</strong>予定です。今しばらくお待ちください。</p>
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
