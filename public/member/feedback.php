<?php

/**
 * 意見箱（会員 → 運営）。
 *
 * 月額未加入でも自己紹介ロック中でも開ける。使いにくい点や困っていることは、
 * むしろ止められている人ほど伝えたいことがあるため、ここは塞がない。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();
$msg = '';
$msgType = 'ok';
$keepKind = '';
$keepBody = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $r = feedback_save((string) $member['id'], $_POST);
    $msg = $r['message'];
    $msgType = $r['ok'] ? 'ok' : 'ng';
    if (!$r['ok']) {
        // 失敗したときは書いた内容を消さない（長文を書き直させないため）。
        $keepKind = (string) ($_POST['kind'] ?? '');
        $keepBody = (string) ($_POST['body'] ?? '');
    }
}

$mine = db()->prepare('SELECT kind, body, handled, created_at FROM feedbacks WHERE member_id = ? ORDER BY id DESC LIMIT 10');
$mine->execute([(string) $member['id']]);
$myList = $mine->fetchAll() ?: [];

$token = csrf_token();
$pageTitle = '意見箱';
$showLogout = true;
require __DIR__ . '/_header.php';
?>
<h1 style="font-size:1.4rem;margin:0 0 4px;">意見箱</h1>
<p class="muted" style="margin:0 0 14px;"><a href="/member/dashboard">← マイページ</a></p>

<?php if ($msg !== ''): ?>
    <div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<div class="card">
    <p class="hint" style="margin:0;">
        使いにくいところ、こんな機能が欲しい、うまく動かない — 何でもお寄せください。運営が全件読みます。<br>
        <strong>どの会員からのご意見かは運営に分かります</strong>（匿名ではありません）。個別のご返信はお約束できませんが、
        改善の判断材料にします。
    </p>
</div>

<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <label>種別</label>
    <select name="kind" required>
        <option value="">選んでください</option>
        <?php foreach (feedback_kinds() as $k => $label): ?>
            <option value="<?= e($k) ?>"<?= $keepKind === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>

    <label>内容</label>
    <textarea name="body" rows="8" maxlength="<?= FEEDBACK_MAX_LEN ?>" required
              placeholder="例：さがすの検索で、業種を複数選べるようにしてほしい。&#10;例：プロフィールの写真を変えたら、前の写真が残って見えることがある。"
              style="width:100%;font-size:.95rem;line-height:1.6;"><?= e($keepBody) ?></textarea>
    <p class="hint" style="margin:4px 0 0;"><?= number_format(FEEDBACK_MAX_LEN) ?>文字まで。</p>

    <p style="margin-top:16px;"><button type="submit" class="btn">送信する</button></p>
</form>

<?php if ($myList !== []): ?>
    <div class="card">
        <div class="card__title">送信したご意見</div>
        <?php foreach ($myList as $f): ?>
            <div style="padding:10px 0;border-top:1px solid var(--border);">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:.8rem;">
                    <span class="badge badge--<?= (int) $f['handled'] === 1 ? 'info' : 'mute' ?>">
                        <?= (int) $f['handled'] === 1 ? '運営が確認しました' : '確認中' ?>
                    </span>
                    <span class="muted"><?= e(feedback_kind_label((string) $f['kind'])) ?></span>
                    <span class="muted"><?= e(date('Y/n/j H:i', (int) $f['created_at'])) ?></span>
                </div>
                <p style="margin:6px 0 0;font-size:.9rem;white-space:pre-wrap;"><?= e((string) $f['body']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
