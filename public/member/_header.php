<?php

/**
 * 会員サイト共通ヘッダ（中央寄せの狭いカード）。
 * ページ側で $pageTitle（任意）／$showLogout（ログイン済みページで true）を設定して require する。
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? '';
$showLogout = $showLogout ?? false;
$wide = $wide ?? false;
$appWide = $appWide ?? false; // true でPC時に広い一覧レイアウト（さがす等）
$hideBrand = $hideBrand ?? false; // true で上部の「Enlink」ブランドバーを非表示
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php
        // 検索結果に出るのはこの文字列。トップ（/）はサービスの説明にする。
        echo basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php'
            ? 'Enlink（縁リンク）｜会員制ビジネスマッチング'
            : e(($pageTitle !== '' ? $pageTitle . ' - ' : '') . 'Enlink 会員サイト');
    ?></title>
    <?php
    // SNSのリンクプレビューと検索向けのメタ情報。
    // 会員限定のページは noindex にする（検索結果にもAIの引用にも載せない）。
    // ※ noindex でも og:* は出す。共有URLをLINEに貼ったときのカードに使うため。
    //   中身は共通のサービス紹介で、会員の名前や写真は出さない。
    $__cur = preg_replace('/\.php$/', '', basename($_SERVER['SCRIPT_NAME'] ?? ''));
    // 索引に載せるのはトップ（/）だけ。/member/login は同じ中身なので、
    // 両方を索引に入れると重複ページになる。あちらは noindex にして一本化する。
    $__isPublic = ($__cur === 'index');
    // 共有URL（/u/<コード>）は、カードに会員の名前を出さない。
    // リンクを見た人全員に本名が見えてしまうため、共通のサービス紹介にそろえる。
    $__ogTitle = ($__isPublic || $__cur === 'u' || $__cur === 'login') ? '' : $pageTitle;
    echo page_meta_tags(['title' => $__ogTitle, 'noindex' => !$__isPublic]);
    if ($__isPublic) {
        echo '    ' . site_jsonld();
    }
    ?>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icon-32.png">
    <link rel="apple-touch-icon" href="/assets/icon-180.png">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
<div class="container<?= $wide ? '' : ' container--narrow' ?><?= $appWide ? ' container--app' : '' ?>">
    <?php if (!$hideBrand): ?>
    <div class="brandbar">Enlink<?php if ($showLogout): ?><a href="/member/logout" style="float:right;font-size:.8rem;">ログアウト</a><?php endif; ?></div>
    <?php endif; ?>
