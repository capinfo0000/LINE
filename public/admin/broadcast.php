<?php

/**
 * 一斉配信（LINE Push）。送信前に推定通数（＝課金）を表示して確認させる二段階フロー。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';
$msgType = 'ok';
$text = (string) ($_POST['text'] ?? '');
$stage = (string) ($_POST['stage'] ?? '');

$recipients = broadcast_recipient_count();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($stage === 'send') {
        if (trim($text) === '') {
            $msg = '本文が空です。';
            $msgType = 'ng';
        } else {
            $sent = broadcast_push($text);
            $msg = "配信しました（送信 {$sent} 件 / 対象 {$recipients} 件）。";
            $text = '';
            $stage = '';
        }
    } elseif ($stage === 'preview') {
        if (trim($text) === '') {
            $msg = '本文を入力してください。';
            $msgType = 'ng';
            $stage = '';
        }
    }
}

$token = csrf_token();
$pageTitle = '一斉配信';
$pageSub = '対象（active・LINE連携済）: ' . $recipients . ' 名';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
    <p>有効会員（LINE連携済）へ Push 配信します。<strong>宛先1件ごとに1通課金</strong>されます。</p>
    <p class="muted">推定通数：<strong><?= $recipients ?> 通</strong></p>
</div>

<?php if ($stage === 'preview'): ?>
    <div class="card">
        <div class="card__title">配信内容の確認</div>
        <p style="white-space:pre-wrap;border:1px solid var(--border);border-radius:8px;padding:10px;background:#f9fafb;"><?= e($text) ?></p>
        <p class="muted">この内容を <strong><?= $recipients ?> 名</strong>（推定 <?= $recipients ?> 通・課金対象）へ送信します。</p>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="stage" value="send">
            <input type="hidden" name="text" value="<?= e($text) ?>">
            <button class="btn" onclick="return confirm('本当に配信しますか？');">この内容で配信する</button>
        </form>
        <a class="btn btn--ghost" href="broadcast.php">やめる</a>
    </div>
<?php else: ?>
    <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="stage" value="preview">
        <label>配信本文</label>
        <textarea name="text" rows="6" maxlength="1000" placeholder="お知らせ内容を入力"><?= e($text) ?></textarea>
        <p style="margin-top:12px;"><button type="submit" class="btn">確認画面へ</button></p>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
