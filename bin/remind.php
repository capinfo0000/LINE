<?php

/**
 * 予約リマインド送信cron（説明会・個別面談）。
 *
 * 開始が近い（既定1時間以内）未リマインドの予約に、公式LINE Bot の Push でリマインドを送る。
 * Push は課金対象。送信済みは claim_reminder で冪等化する。多重起動ロックあり。
 *
 * cron 例（php85cli・5分おき）:
 *   （分の欄に5分間隔を指定）  php85cli /home/<acct>/enlink/bin/remind.php >> /home/<acct>/private/remind.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI からのみ実行できます。\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$lockPath = dirname(current_db_path()) . '/remind.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "別のリマインド処理が実行中です。スキップします。\n");
    exit(0);
}

$sent = 0;
$window = (int) env('REMIND_WINDOW_SEC', '3600');

foreach (bookings_needing_reminder($window) as $b) {
    if (empty($b['line_user_id'])) {
        continue;
    }
    if (!claim_reminder((string) $b['id'])) {
        continue; // 既送信
    }
    $label = ($b['kind'] ?? '') === 'seminar' ? '説明会' : '個別面談';
    $when = line_jst_label((int) $b['slot_start']);
    $text = "まもなく{$label}のお時間です。\n日時：{$when}\n";
    if (!empty($b['zoom_url'])) {
        $text .= "参加URL：{$b['zoom_url']}\n";
    }
    $text .= "お待ちしております。";
    if (line_push((string) $b['line_user_id'], [line_text($text)])) {
        $sent++;
    }
}

flock($lock, LOCK_UN);
fclose($lock);
fwrite(STDOUT, sprintf("[%s] リマインド送信: %d件\n", date('c'), $sent));
