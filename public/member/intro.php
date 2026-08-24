<?php

/**
 * 自己紹介ひな形（グループLINE / オープンチャット用）。
 * 「enlinkから来ました○○です。」＋自分のプロフィールを整形したテキストを生成し、
 * 本人が編集して1タップでコピー→オープンチャットに投稿できるようにする。
 * ※投稿の徹底（自分が投稿しないと他人の投稿が見えない等）はオープンチャット側の運用ルール。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();
$memberId = (string) $member['id'];
$profile = get_profile($memberId);
$labels = member_tag_labels($memberId);

$name = ($profile['name_text'] ?? '') !== '' ? (string) $profile['name_text'] : 'お名前';
$company = (string) ($profile['company_title'] ?? '');
$headline = (string) ($profile['headline'] ?? '');
$bio = (string) ($profile['bio'] ?? '');
$area = implode('・', $labels['area'] ?? []);
$job = implode('・', $labels['job'] ?? []);
$purpose = implode('・', $labels['purpose'] ?? []);
$offer = implode('・', $labels['offer'] ?? []);

// ひな形の組み立て。空の項目は行ごと省略。
$lines = [];
$lines[] = "enlinkから来ました、{$name}です。よろしくお願いします！";
$lines[] = '';
if ($company !== '') {
    $lines[] = "■ 会社・肩書き\n{$company}";
}
if ($headline !== '') {
    $lines[] = "■ ひとことPR\n{$headline}";
}
if ($area !== '' || $job !== '') {
    $lines[] = '■ エリア／業種：' . trim($area . ($area !== '' && $job !== '' ? '／' : '') . $job);
}
if ($purpose !== '') {
    $lines[] = "■ 求めていること\n{$purpose}";
}
if ($offer !== '') {
    $lines[] = "■ 提供できること\n{$offer}";
}
if ($bio !== '') {
    $lines[] = "■ 自己紹介\n{$bio}";
}
$template = implode("\n", $lines);

$profileThin = ($profile['name_text'] ?? '') === '' && $headline === '' && $bio === '';

$pageTitle = '自己紹介ひな形';
$showLogout = true;
$hideBrand = true;
require __DIR__ . '/_header.php';
?>
<h1 style="font-size:1.4rem;margin:0 0 4px;">自己紹介ひな形</h1>
<p class="muted" style="margin:0 0 12px;"><a href="/member/dashboard.php">← マイページ</a></p>

<div class="flash" style="background:#fff7f6;border:1px solid var(--coral-soft);">
    <b style="color:var(--coral-d);">グループLINE（オープンチャット）での最初のあいさつ</b>
    <p style="margin:6px 0 0;font-size:.9rem;">
        入室したら、まず自己紹介を投稿しましょう。下のひな形を<strong>自由に編集</strong>して、
        「コピー」ボタンでコピー → オープンチャットに貼り付けて送信してください。
    </p>
</div>

<?php if ($profileThin): ?>
<div class="flash flash--ng">
    プロフィールが未入力です。先に <a href="/member/profile.php">プロフィールを編集</a> すると、より充実したひな形が作れます。
</div>
<?php endif; ?>

<div class="card">
    <div class="card__title" style="color:var(--coral-d);">あなたのひな形（編集できます）</div>
    <textarea id="introText" rows="14" style="width:100%;font-size:.95rem;line-height:1.6;"><?= e($template) ?></textarea>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <button type="button" class="btn" data-copy-target="introText" data-copied-label="✓ コピーしました">この内容をコピー</button>
        <a class="btn btn--ghost" href="/member/profile.php">プロフィールを編集</a>
    </div>
    <p class="muted" style="font-size:.8rem;margin:10px 0 0;">
        ※ コピーした内容はオープンチャットに貼り付けて送信してください。ここでの編集は保存されません（プロフィール本体は「プロフィール編集」から更新できます）。
    </p>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
