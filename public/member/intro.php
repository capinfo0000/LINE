<?php

/**
 * 自己紹介ひな形（公式LINE送信用）。
 * 「enlinkから来ました○○です。」＋自分のプロフィールを整形したテキストを生成し、
 * 本人が編集・保存して1タップでコピー→公式LINEのトークに送信できるようにする。
 * ※公式LINEに自己紹介を送るとWebhookで検知し、「さがす」の閲覧ロックが自動解除される。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

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
$age = profile_age_text($profile);
$occupation = (string) ($profile['occupation'] ?? '');
$headline = (string) ($profile['headline'] ?? '');
$bio = (string) ($profile['bio'] ?? '');
$area = implode('・', $labels['area'] ?? []);
$job = implode('・', $labels['job'] ?? []);
$purpose = implode('・', $labels['purpose'] ?? []);
$offer = implode('・', $labels['offer'] ?? []);

// ひな形の組み立て。項目ラベルだけを用意し、値はプロフィールがあれば補完、無ければ空欄。
// 会員は入力欄の中で各項目のあとに自由に入力できる。
$row = static function (string $label, string $val): string {
    return "■ {$label}：{$val}";
};
$nameVal = $name === 'お名前' ? '' : $name;
$lines = [];
$lines[] = $nameVal !== '' ? "enlinkから来ました、{$nameVal}です。よろしくお願いします！" : 'enlinkから来ました。よろしくお願いします！';
$lines[] = '';
$lines[] = $row('名前', $nameVal);
$lines[] = $row('年齢', $age);
$lines[] = $row('職業', $occupation);
$lines[] = $row('業種', $job);
$lines[] = $row('エリア', $area);
$lines[] = $row('ひとことPR', $headline);
$lines[] = $row('求めていること', $purpose);
$lines[] = $row('提供できること', $offer);
$lines[] = '';
$lines[] = '■ 自己紹介';
$lines[] = $bio;
$template = rtrim(implode("\n", $lines)) . "\n";

// 表示する文面：保存済みがあればそれを優先（?reset=1 のときは作り直したテンプレ）。
$display = (!$reset && $savedIntro !== '') ? $savedIntro : $template;

$profileThin = ($profile['name_text'] ?? '') === '' && $headline === '' && $bio === '';
$token = csrf_token();
// 公式LINEのトークURL：管理画面「各種設定」優先、無ければ .env をフォールバック。
$officialUrl = site_setting('line_official_url');
if ($officialUrl === '') {
    $officialUrl = (string) (env('LINE_OFFICIAL_URL', '') ?? '');
}
$gated = member_needs_intro($member); // まだ公式LINEに送っていない＝ロック中

$pageTitle = '自己紹介ひな形';
$showLogout = true;
$hideBrand = true;
require __DIR__ . '/_header.php';
?>
<h1 style="font-size:1.4rem;margin:0 0 4px;">自己紹介を公式LINEに送る</h1>
<p class="muted" style="margin:0 0 12px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
    <a href="/member/dashboard">← マイページ</a>
    <?php if ($officialUrl !== ''): ?>
        <a href="<?= e($officialUrl) ?>" target="_blank" rel="noopener" style="color:#06c755;font-weight:700;">公式LINEを開く →</a>
    <?php endif; ?>
</p>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<?php if ($gated): ?>
<div class="flash flash--ng">
    🔒 まず<strong>公式LINEに自己紹介を送信</strong>してください。確認できると「さがす」が使えます。
    <?php if ($officialUrl === ''): ?>
        <div style="font-size:.84rem;font-weight:400;margin-top:6px;">
            下の「コピー」で文章を写したあと、<strong>LINEアプリで Enlink の公式アカウントのトーク</strong>を開いて貼り付けて送ってください
            （入会のご案内をお送りしたトークです）。
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
    <textarea id="introText" name="intro_text" rows="15" style="width:100%;font-size:.95rem;line-height:1.6;"><?= e($display) ?></textarea>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <button type="submit" class="btn">保存</button>
        <button type="button" class="btn btn--ghost" data-copy-target="introText" data-copied-label="✓ コピーしました">コピー</button>
        <?php if ($officialUrl !== ''): ?>
            <a class="btn" href="<?= e($officialUrl) ?>" target="_blank" rel="noopener" style="background:#06c755;border-color:#06c755;">公式LINEを開く →</a>
        <?php endif; ?>
        <a class="btn btn--ghost" href="/member/intro?reset=1" data-confirm="プロフィールから作り直します（未保存の編集は消えます）。よろしいですか？">作り直す</a>
    </div>
    <?php if ($officialUrl === ''): ?>
        <p class="hint" style="margin:10px 0 0;">
            「コピー」で文章を写したら、LINEアプリで Enlink の公式アカウントのトークを開いて貼り付けて送信してください。
        </p>
    <?php endif; ?>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
