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
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    save_intro_text($memberId, (string) ($_POST['intro_text'] ?? ''));
    $msg = '自己紹介ひな形を保存しました。';
}

$profile = get_profile($memberId);
$labels = member_tag_labels($memberId);
$savedIntro = (string) ($profile['intro_text'] ?? '');
// ?reset=1 で保存内容を無視してプロフィールから作り直す。
$reset = isset($_GET['reset']);

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

// 表示する文面：保存済みがあればそれを優先（?reset=1 のときは作り直したテンプレ）。
$display = (!$reset && $savedIntro !== '') ? $savedIntro : $template;

$profileThin = ($profile['name_text'] ?? '') === '' && $headline === '' && $bio === '';
$token = csrf_token();
$openChatUrl = active_openchat_url();

$pageTitle = '自己紹介ひな形';
$showLogout = true;
$hideBrand = true;
require __DIR__ . '/_header.php';
?>
<h1 style="font-size:1.4rem;margin:0 0 4px;">自己紹介ひな形</h1>
<p class="muted" style="margin:0 0 12px;"><a href="/member/dashboard.php">← マイページ</a></p>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="flash" style="background:#fff7f6;border:1px solid var(--coral-soft);">
    <b style="color:var(--coral-d);">グループLINE（オープンチャット）での最初のあいさつ</b>
    <p style="margin:6px 0 0;font-size:.9rem;">
        入室したら、まず自己紹介を投稿しましょう。手順は簡単3ステップです。
    </p>
    <ol style="margin:8px 0 0;padding-left:1.2em;font-size:.88rem;">
        <li>下のひな形を<strong>編集して「保存」</strong></li>
        <li><strong>「コピー」</strong>で本文をコピー</li>
        <li><strong>オープンチャットを開いて貼り付け→送信</strong></li>
    </ol>
</div>

<?php if ($profileThin): ?>
<div class="flash flash--ng">
    プロフィールが未入力です。先に <a href="/member/profile.php">プロフィールを編集</a> すると、より充実したひな形が作れます。
</div>
<?php endif; ?>

<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
    <div class="card__title" style="color:var(--coral-d);">あなたのひな形（編集して保存できます）</div>
    <textarea id="introText" name="intro_text" rows="14" style="width:100%;font-size:.95rem;line-height:1.6;"><?= e($display) ?></textarea>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <button type="submit" class="btn">保存する</button>
        <button type="button" class="btn btn--ghost" data-copy-target="introText" data-copied-label="✓ コピーしました">コピー</button>
        <a class="btn btn--ghost" href="/member/intro.php?reset=1" data-confirm="プロフィールから作り直します（未保存の編集は消えます）。よろしいですか？">テンプレートに戻す</a>
    </div>
    <?php if ($openChatUrl !== null): ?>
        <p style="margin:14px 0 0;"><a class="btn" href="<?= e($openChatUrl) ?>" target="_blank" rel="noopener" style="background:#06c755;border-color:#06c755;">オープンチャットを開く →</a></p>
        <p class="muted" style="font-size:.8rem;margin:6px 0 0;">コピーしてから開くと、そのまま貼り付けて送信できます。</p>
    <?php else: ?>
        <p class="muted" style="font-size:.8rem;margin:12px 0 0;">※ オープンチャットの参加URLは、入会時のLINEメッセージからご確認ください。</p>
    <?php endif; ?>
    <p class="muted" style="font-size:.8rem;margin:10px 0 0;">
        ※ 「保存する」で内容を保存できます。コピーした内容はオープンチャットに貼り付けて送信してください。
        <?php if ($savedIntro !== '' && !$reset): ?><br>現在は<strong>保存済みの内容</strong>を表示しています。<?php endif; ?>
    </p>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
