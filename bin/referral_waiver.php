<?php

/**
 * 紹介特典（月額無料化）の判定cron。
 *
 * 各 active サブスク会員について「アクティブな紹介先」の人数を数え、しきい値(既定5)以上なら
 * 100%OFFクーポンを適用して月額無料に、割り込んだら解除して通常額に戻す。
 * 判定モード（A案=無料化した紹介先も数える／B案=課金中のみ数える）は
 * app_settings 'referral_waiver_mode' で切替（運営ダッシュボード）。
 *
 * 処理は冪等（subscription_waived フラグで Stripe への二重適用を防止）。多重起動ロックあり。
 *
 * cron 例（php85cli・1日1回・請求日直前に判定されれば十分）:
 *   （毎日 深夜など）  php85cli /home/<acct>/enlink/bin/referral_waiver.php >> /home/<acct>/private/waiver.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI からのみ実行できます。\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

// 課金制度そのものが始まっていなければ対象は存在しないので即終了（無駄な Stripe 呼び出しを避ける）。
// 猶予期間は対象に含める。ここで判定しないと、猶予期間に条件を達成した人が初回請求に間に合わない。
if (billing_reached_at() === null) {
    fwrite(STDOUT, sprintf("[%s] まだ課金制度が始まっていないためスキップ\n", date('c')));
    exit(0);
}

$lockPath = dirname(current_db_path()) . '/waiver.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "別の無料化判定処理が実行中です。スキップします。\n");
    exit(0);
}

try {
    init_stripe();
    $r = evaluate_referral_waiver();
    fwrite(STDOUT, sprintf(
        "[%s] 紹介特典判定(mode=%s): earned=%d scanned=%d applied=%d removed=%d notified=%d errors=%d\n",
        date('c'),
        $r['mode'],
        $r['earned'],
        $r['scanned'],
        $r['applied'],
        $r['removed'],
        $r['notified'],
        $r['errors']
    ));
} catch (\Throwable $e) {
    fwrite(STDERR, '無料化判定エラー: ' . $e->getMessage() . "\n");
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

flock($lock, LOCK_UN);
fclose($lock);
