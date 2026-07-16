<?php

/**
 * 会員トップ（ログイン後）。Phase 1 時点は最小の枠。
 * プロフィール編集・ディレクトリ検索・おすすめは後続フェーズで追加する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member(); // 未ログイン→login、初回PW未変更→change_password へ誘導
$flash = (string) ($_GET['msg'] ?? '');
$flashType = (string) ($_GET['type'] ?? '');

$pageTitle = '会員トップ';
$showLogout = true;
require __DIR__ . '/_header.php';
?>
<?php if ($flash !== ''): ?>
    <div class="flash <?= $flashType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<h1>ようこそ<?= $member['display_name'] !== '' ? '、' . e($member['display_name']) . ' さん' : '' ?></h1>

<div class="card">
    <div class="card__title">会員サイト</div>
    <p>ご登録ありがとうございます。プロフィール編集・会員ディレクトリの検索・条件に合う相手のおすすめ表示は、
       順次ご利用いただけるようになります（準備中）。</p>
    <p class="muted">ログインID：<code><?= e($member['login_id']) ?></code></p>
</div>

<div class="card">
    <div class="card__title">アカウント</div>
    <p><a class="btn btn--ghost" href="/member/change_password.php">パスワードを変更</a></p>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
