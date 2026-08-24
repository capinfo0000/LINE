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
$ageRaw = (string) ($profile['age_text'] ?? '');
$age = $ageRaw === '' ? '' : (ctype_digit($ageRaw) ? $ageRaw . '歳' : $ageRaw);
$company = (string) ($profile['company_title'] ?? '');
$headline = (string) ($profile['headline'] ?? '');
$bio = (string) ($profile['bio'] ?? '');
$area = implode('・', $labels['area'] ?? []);
$job = implode('・', $labels['job'] ?? []);
$purpose = implode('・', $labels['purpose'] ?? []);
$offer = implode('・', $labels['offer'] ?? []);

// ひな形の組み立て。1行1項目のわかりやすい形。空の項目は「（未設定）」で残し、編集を促す。
$row = static function (string $label, string $val): string {
    return "■ {$label}：" . ($val !== '' ? $val : '（未設定）');
};
$lines = [];
$lines[] = "enlinkから来ました、{$name}です。よろしくお願いします！";
$lines[] = '';
$lines[] = $row('名前', $name === 'お名前' ? '' : $name);
$lines[] = $row('年齢', $age);
$lines[] = $row('職業・業種', $job);
$lines[] = $row('会社・肩書き', $company);
$lines[] = $row('エリア', $area);
$lines[] = $row('ひとことPR', $headline);
$lines[] = $row('求めていること', $purpose);
$lines[] = $row('提供できること', $offer);
$lines[] = '';
$lines[] = '■ 自己紹介';
$lines[] = $bio !== '' ? $bio : '（自己紹介を入力してください）';
$template = implode("\n", $lines);

// 表示する文面：保存済みがあればそれを優先（?reset=1 のときは作り直したテンプレ）。
$display = (!$reset && $savedIntro !== '') ? $savedIntro : $template;

$profileThin = ($profile['name_text'] ?? '') === '' && $headline === '' && $bio === '';
$token = csrf_token();
$officialUrl = (string) (env('LINE_OFFICIAL_URL', '') ?? '');
$gated = member_needs_intro($member); // まだ公式LINEに送っていない＝ロック中

$pageTitle = '自己紹介ひな形';
$showLogout = true;
$hideBrand = true;
require __DIR__ . '/_header.php';
?>
<h1 style="font-size:1.4rem;margin:0 0 4px;">自己紹介を公式LINEに送る</h1>
<p class="muted" style="margin:0 0 12px;"><a href="/member/dashboard.php">← マイページ</a></p>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<?php if ($gated): ?>
<div class="flash flash--ng" style="font-weight:600;">
    🔒 「さがす」を見るには、まず<strong>公式LINEのトークに自己紹介を送信</strong>してください。<br>
    <span style="font-weight:400;font-size:.88rem;">送信が確認されると自動で解除されます（反映まで少し時間がかかる場合があります）。</span>
</div>
<?php endif; ?>

<div class="flash" style="background:#fff7f6;border:1px solid var(--coral-soft);">
    <b style="color:var(--coral-d);">公式LINEへの最初のあいさつ</b>
    <p style="margin:6px 0 0;font-size:.9rem;">下のひな形を送るだけ。手順は簡単3ステップです。</p>
    <ol style="margin:8px 0 0;padding-left:1.2em;font-size:.88rem;">
        <li>下のひな形を<strong>編集して「保存」</strong></li>
        <li><strong>「コピー」</strong>で本文をコピー</li>
        <li><strong>公式LINEのトークを開いて貼り付け→送信</strong></li>
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
    <?php if ($officialUrl !== ''): ?>
        <p style="margin:14px 0 0;"><a class="btn" href="<?= e($officialUrl) ?>" target="_blank" rel="noopener" style="background:#06c755;border-color:#06c755;">公式LINEを開く →</a></p>
        <p class="muted" style="font-size:.8rem;margin:6px 0 0;">コピーしてから開くと、そのまま貼り付けて送信できます。</p>
    <?php else: ?>
        <p class="muted" style="font-size:.8rem;margin:12px 0 0;">※ コピーしたら、Enlink公式LINEのトークを開いて貼り付け、送信してください。</p>
    <?php endif; ?>
    <p class="muted" style="font-size:.8rem;margin:10px 0 0;">
        ※ 「保存する」で内容を保存できます。コピーした内容は<strong>公式LINEのトーク</strong>に貼り付けて送信してください。
        <?php if ($savedIntro !== '' && !$reset): ?><br>現在は<strong>保存済みの内容</strong>を表示しています。<?php endif; ?>
    </p>
</form>

<?php
$example = "enlinkから来ました、山田 太郎です。よろしくお願いします！\n\n"
    . "■ 名前：山田 太郎\n"
    . "■ 年齢：38歳\n"
    . "■ 職業・業種：IT・Web・通信\n"
    . "■ 会社・肩書き：山田システム株式会社 / 代表\n"
    . "■ エリア：東京\n"
    . "■ ひとことPR：中小企業のDX・業務システム開発が得意です\n"
    . "■ 求めていること：協業・販路開拓\n"
    . "■ 提供できること：技術・開発・ノウハウ提供\n\n"
    . "■ 自己紹介\n"
    . "受託開発15年。最近は補助金を活用したシステム導入の支援に力を入れています。異業種の経営者の方とつながって、一緒に新しいサービスを作れたら嬉しいです。お気軽にメッセージください！";
?>
<div class="card">
    <div class="card__title" style="color:var(--coral-d);">記入例</div>
    <p class="muted" style="margin-top:0;font-size:.85rem;">こんな感じで書くと伝わりやすいです（コピーして参考にどうぞ）。</p>
    <pre id="introExample" style="white-space:pre-wrap;word-break:break-word;background:#faf6f5;border:1px solid var(--border);border-radius:12px;padding:14px;font:inherit;font-size:.9rem;line-height:1.6;margin:0;"><?= e($example) ?></pre>
    <p style="margin:10px 0 0;"><button type="button" class="btn btn--ghost" data-copy-target="introExample" data-copied-label="✓ コピーしました">記入例をコピー</button></p>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
