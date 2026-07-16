<?php

/**
 * 入会金決済の照合cron（Webhook取りこぼしの救済）。
 *
 * Stripe から直近の完了 Checkout セッション（mode=payment / paid / metadata.purpose=join_fee）を取得し、
 * まだ payments に記録の無いものを provision する。処理は冪等（claim-first）なので Webhook と競合しても安全。
 *
 * cron 例（php85cli・10分おき）:
 *   （分の欄に「10分間隔」を指定）  php85cli /home/<acct>/akmatching/bin/reconcile.php >> /home/<acct>/private/reconcile.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI からのみ実行できます。\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

// 多重起動ロック（前回が長引いても二重処理を避ける。処理自体は冪等だが無駄を減らす）。
$lockPath = dirname(current_db_path()) . '/reconcile.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "別の照合処理が実行中です。スキップします。\n");
    exit(0);
}

$since = time() - 3 * 86400; // 直近3日分を対象
$provisioned = 0;
$scanned = 0;

try {
    init_stripe();
    $params = [
        'limit' => 100,
        'created' => ['gte' => $since],
        'expand' => ['data.customer_details'],
    ];
    foreach (\Stripe\Checkout\Session::all($params)->autoPagingIterator() as $session) {
        $scanned++;
        if (($session->mode ?? '') !== 'payment') {
            continue;
        }
        if (($session->payment_status ?? '') !== 'paid') {
            continue;
        }
        if ((string) ($session->metadata->purpose ?? '') !== 'join_fee') {
            continue;
        }
        if (find_payment_by_session((string) $session->id) !== null) {
            continue; // 既に処理済み
        }
        $result = provision_member_from_checkout_session(normalize_checkout_session($session));
        if (($result['status'] ?? '') === 'done') {
            $provisioned++;
            fwrite(STDOUT, "provisioned: session={$session->id} member=" . ($result['member_id'] ?? '-') . "\n");
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '照合エラー: ' . $e->getMessage() . "\n");
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

flock($lock, LOCK_UN);
fclose($lock);
fwrite(STDOUT, sprintf("[%s] 照合完了: scanned=%d provisioned=%d\n", date('c'), $scanned, $provisioned));
