<?php

/**
 * おすすめ再構築cron（週次）。全有効会員の双方向おすすめを計算して recommendations に保存する。
 * 会員サイトの表示はライブ計算だが、集計・分析用に永続化する。多重起動ロックあり。
 *
 * cron 例（php85cli・週次）:
 *   （毎週1回）  php85cli /home/<acct>/enlink/bin/recommend.php >> /home/<acct>/private/recommend.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI からのみ実行できます。\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

$lockPath = dirname(current_db_path()) . '/recommend.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "別の再構築処理が実行中です。スキップします。\n");
    exit(0);
}

$total = rebuild_all_recommendations();

flock($lock, LOCK_UN);
fclose($lock);
fwrite(STDOUT, sprintf("[%s] おすすめ再構築: %d件\n", date('c'), $total));
