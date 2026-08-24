<?php

/**
 * トップ（/）＝会員ログイン画面。
 * URL を変えずにログインフォームを表示するため、会員ログインページをそのまま読み込む。
 * （運営ログインは公開トップからは案内しない＝管理URLを露出させない）
 */

declare(strict_types=1);

require __DIR__ . '/member/login.php';
