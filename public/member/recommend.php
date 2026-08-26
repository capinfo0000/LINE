<?php

/**
 * あなたへのおすすめ（双方向マッチ）。
 * 「あなたの求める条件に相手が合致」かつ「相手の求める条件にあなたが合致」した相手のみ表示する。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();

// 自己紹介を公式LINEに送るまでロック（さがすのロック画面へ）。
if (member_search_locked($member)) {
    header('Location: /member/directory'); // さがす画面で理由と支払い導線を出す
    exit;
}
if (member_needs_intro($member)) {
    header('Location: /member/directory');
    exit;
}

$recs = compute_recommendations_for($member['id'], 20);

// プランによる表示数の制限（無料フェーズ／プレミアムは無制限）。
$recMax = plan_recommend_max($member);
$recCapped = false;
if ($recMax > 0 && count($recs) > $recMax) {
    $recs = array_slice($recs, 0, $recMax);
    $recCapped = true;
}

// 求める条件・属性が未設定だと双方向マッチは出にくい旨を案内する。
$prefs = get_preferences($member['id']);
$attrs = member_attributes($member['id']);
$profileThin = ($prefs['seek_area'] === [] && $prefs['seek_job'] === [] && $prefs['seek_purpose'] === [])
    || ($attrs['area'] === [] && $attrs['job'] === [] && $attrs['purpose'] === [] && $attrs['offer'] === []);

$pageTitle = 'あなたへのおすすめ';
$showLogout = true;
$wide = true;
$hideBrand = true;
require __DIR__ . '/_header.php';
?>
<h1 style="font-size:1.5rem;margin:0 0 4px;">あなたへのおすすめ</h1>
<p class="muted"><a href="/member/directory">← さがすへ</a></p>
<p class="muted">「あなたの求める条件に合う」かつ「相手の求める条件にもあなたが合う」相手だけを表示しています。</p>

<?php if ($profileThin): ?>
    <div class="flash flash--ng">
        プロフィールの<strong>タグ</strong>や<strong>求める条件</strong>が未設定だと、双方向マッチのおすすめが出にくくなります。
        <a href="/member/profile">プロフィールを編集</a>してください。
    </div>
<?php endif; ?>

<?php if ($recs === []): ?>
    <div class="card"><p style="margin:0;">現在、条件が双方合致する相手が見つかりませんでした。条件を見直すか、時間をおいて再度ご確認ください。</p></div>
<?php else: ?>
    <?php foreach ($recs as $r):
        $labels = member_tag_labels($r['member_id']);
        $hasApprovedPhoto = profile_has_photo($r);
        $bal = member_points_earned($r['member_id']); // 累計獲得（称号の基準）
    ?>
        <div class="card">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <?php if ($hasApprovedPhoto): ?>
                    <img src="<?= e(member_photo_url($r)) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:10px;flex:none;">
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;">
                        <a href="<?= e(member_public_path($r)) ?>"><?= e($r['name'] !== '' ? $r['name'] : '会員') ?></a>
                        <?php $__age = profile_age_text($r); ?><?php if ($__age !== ''): ?><span class="muted" style="font-weight:normal;">（<?= e($__age) ?>）</span><?php endif; ?>
                        <span style="float:right;font-size:.78rem;color:var(--info-fg);">マッチ度 <?= (int) $r['score'] ?></span>
                    </div>
                    <div style="margin:3px 0;"><span class="badge badge--title"><?= e(member_title_by_id((string) $r['member_id'])) ?></span> <span class="muted" style="font-size:.82rem;"><?= number_format($bal) ?> pt</span></div>
                    <?php if (($r['company_title'] ?? '') !== ''): ?><div class="muted"><?= e($r['company_title']) ?></div><?php endif; ?>
                    <?php if (($r['headline'] ?? '') !== ''): ?><div style="margin:4px 0;"><?= e($r['headline']) ?></div><?php endif; ?>
                    <ul style="margin:6px 0 0;padding-left:18px;">
                        <?php foreach ($r['reasons'] as $reason): ?>
                            <li style="font-size:.86rem;color:#374151;"><?= e($reason) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="margin-top:6px;">
                        <?php foreach (['area', 'job', 'purpose', 'offer'] as $cat): ?>
                            <?php foreach ($labels[$cat] ?? [] as $lb): ?>
                                <span class="chipmini" style="margin:2px 4px 2px 0;"><?= e($lb) ?></span>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($recCapped): ?>
        <div class="card" style="text-align:center;background:#f8fafc;">
            <p style="margin:.2rem 0;">ベーシックプランでは1日に表示できるおすすめは <strong><?= (int) $recMax ?>人</strong>までです。</p>
            <?php /* 登録できる時期なら申込画面へ直接。billing には手続きボタンが無く、
                     「運営までご連絡ください」で行き止まりになるため。 */ ?>
            <p style="margin:.2rem 0;"><a class="btn" href="<?= member_can_subscribe_now($member) ? '/member/subscribe' : '/member/billing' ?>">プレミアムで全員を見る</a></p>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
