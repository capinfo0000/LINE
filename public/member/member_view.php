<?php

/**
 * 会員プロフィール詳細（会員のみ閲覧）。LINE追加URLは所有者の表示設定に従う。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$viewer = require_member();
$targetId = (string) ($_GET['id'] ?? '');

$view = $targetId !== '' ? viewable_member_profile($targetId) : null;
if ($view === null) {
    http_response_code(404);
    $pageTitle = '会員が見つかりません';
    $showLogout = true;
    require __DIR__ . '/_header.php';
    echo '<div class="card"><p>指定の会員は表示できません。</p></div>';
    echo '<p><a href="/member/directory.php">← ディレクトリへ戻る</a></p>';
    require __DIR__ . '/_footer.php';
    exit;
}

$member = $view['member'];
$profile = $view['profile'];
$labels = member_tag_labels($targetId);
$links = visible_member_links($targetId, $profile);
$hasApprovedPhoto = ($profile['photo_status'] ?? '') === 'approved';

$pageTitle = ($profile['name_text'] !== '' ? $profile['name_text'] : '会員') . ' さんのプロフィール';
$showLogout = true;
require __DIR__ . '/_header.php';
?>
<p class="muted"><a href="/member/directory.php">← ディレクトリへ戻る</a></p>

<div class="card">
    <div style="display:flex;gap:14px;align-items:flex-start;">
        <?php if ($hasApprovedPhoto): ?>
            <img src="/member/photo.php?id=<?= e($targetId) ?>" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:12px;flex:none;">
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
            <h1 style="margin:0 0 4px;"><?= e($profile['name_text'] !== '' ? $profile['name_text'] : '会員') ?>
                <?php if (($profile['age_text'] ?? '') !== ''): ?><span class="muted" style="font-size:1rem;font-weight:normal;">（<?= e($profile['age_text']) ?>）</span><?php endif; ?>
            </h1>
            <?php if (($profile['company_title'] ?? '') !== ''): ?><div class="muted"><?= e($profile['company_title']) ?></div><?php endif; ?>
            <?php if (($profile['headline'] ?? '') !== ''): ?><p style="margin:6px 0 0;font-weight:600;"><?= e($profile['headline']) ?></p><?php endif; ?>
        </div>
    </div>
</div>

<?php if (($profile['bio'] ?? '') !== ''): ?>
<div class="card">
    <div class="card__title">自己紹介</div>
    <p style="white-space:pre-wrap;"><?= e($profile['bio']) ?></p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card__title">タグ</div>
    <?php foreach (['area' => '場所', 'job' => '仕事', 'purpose' => '目的', 'offer' => '提供できること'] as $cat => $clabel): ?>
        <?php if (!empty($labels[$cat])): ?>
            <p style="margin:4px 0;"><span class="muted"><?= e($clabel) ?>：</span>
                <?php foreach ($labels[$cat] as $lb): ?>
                    <span style="display:inline-block;background:#eef2ff;color:#3730a3;border-radius:10px;padding:1px 8px;font-size:.82rem;margin:2px 4px 2px 0;"><?= e($lb) ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if ($links !== []): ?>
<div class="card">
    <div class="card__title">リンク・連絡先</div>
    <?php foreach ($links as $l): ?>
        <p style="margin:6px 0;">
            <?php if (($l['kind'] ?? '') === 'line_add'): ?>
                <span style="display:inline-block;background:#06c755;color:#fff;border-radius:8px;padding:2px 8px;font-size:.8rem;">LINE</span>
            <?php endif; ?>
            <?= ($l['label'] ?? '') !== '' ? e($l['label']) . '：' : '' ?>
            <a href="<?= e($l['url']) ?>" target="_blank" rel="noopener nofollow"><?= e($l['url']) ?></a>
        </p>
    <?php endforeach; ?>
    <p class="muted">LINE追加URLから、その場でつながれます。</p>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
