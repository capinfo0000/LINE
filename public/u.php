<?php

/**
 * プロフィールの短い共有URL（/u/<共有コード>）の受け口。
 *
 * 共有コードから会員を引き当て、あとは通常のプロフィール詳細（member_view.php）に
 * そのまま処理を渡す。リダイレクトはしないので、アドレスバーは短いURLのままになる。
 * 閲覧できるかどうかの判定（ログイン・自己紹介ロック・月額・公開設定）は
 * member_view.php 側でこれまでどおり行われる。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$code = (string) ($_GET['c'] ?? '');
$member = find_member_by_public_code($code);

if ($member === null) {
    http_response_code(404);
    $pageTitle = 'ページが見つかりません';
    require __DIR__ . '/member/_header.php';
    echo '<div class="card"><p style="margin:0;">このURLのプロフィールは見つかりませんでした。'
        . 'リンクが古いか、間違っている可能性があります。</p></div>';
    echo '<p><a class="btn btn--ghost" href="/member/login">会員サイトへ</a></p>';
    require __DIR__ . '/member/_footer.php';
    exit;
}

// 未ログインの人がこの共有URLを開いたときは、ログイン後にこの画面へ戻す。
// （member_view.php 側の require_member() がログイン画面へ飛ばす前に覚えておく）
set_login_return_path('/u/' . member_public_code($member));

// 以降は通常のプロフィール詳細と同じ処理にする。
$_GET['id'] = (string) $member['id'];
require __DIR__ . '/member/member_view.php';
