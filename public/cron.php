<?php

/**
 * cron 用 Web エンドポイント（CLIが使いにくい共用ホスト向け）。
 * トークン保護。CoreServer 等の cron から curl で叩く：
 *   curl -s "https://<ドメイン>/cron.php?job=remind&token=<CRON_TOKEN>"
 *   job = remind（予約リマインド）/ reconcile（Stripe照合）/ recommend（おすすめ再構築）
 *       / thankyou（説明会後の意向確認）/ waiver（紹介特典の月額無料化判定）
 *
 * ※ CLI 版（bin/reconcile.php 等）と同じ処理を Web から実行する。多重起動ロックあり・冪等。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');

// トークン照合（CRON_TOKEN 未設定 or 不一致は拒否）。
$expected = (string) (env('CRON_TOKEN', '') ?? '');
$given = (string) ($_GET['token'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit("forbidden\n");
}

$job = (string) ($_GET['job'] ?? '');
if (!in_array($job, ['remind', 'reconcile', 'recommend', 'thankyou', 'waiver', 'seed', 'unseed', 'samplephotos', 'diag'], true)) {
    http_response_code(400);
    exit("unknown job\n");
}

// 多重起動ロック（ジョブごと）。
$lock = fopen(dirname(current_db_path()) . "/cron_{$job}.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("locked (already running)\n");
}

$out = '';
try {
    if ($job === 'remind') {
        $window = (int) env('REMIND_WINDOW_SEC', '3600');
        $sent = 0;
        foreach (bookings_needing_reminder($window) as $b) {
            if (empty($b['line_user_id'])) {
                continue;
            }
            if (!claim_reminder((string) $b['id'])) {
                continue;
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
        $out = "remind sent={$sent}";
    } elseif ($job === 'reconcile') {
        $since = time() - 3 * 86400;
        $scanned = 0;
        $provisioned = 0;
        init_stripe();
        foreach (\Stripe\Checkout\Session::all(['limit' => 100, 'created' => ['gte' => $since], 'expand' => ['data.customer_details']])->autoPagingIterator() as $session) {
            $scanned++;
            // 買い切り(payment・旧) と 月額サブスク(subscription) の両方を救済対象にする。
            $mode = (string) ($session->mode ?? '');
            if ($mode !== 'payment' && $mode !== 'subscription') {
                continue;
            }
            if (($session->payment_status ?? '') !== 'paid') {
                continue;
            }
            if (!in_array((string) ($session->metadata->purpose ?? ''), ['join_fee', 'subscription'], true)) {
                continue;
            }
            if (find_payment_by_session((string) $session->id) !== null) {
                continue;
            }
            $r = provision_member_from_checkout_session(normalize_checkout_session($session));
            if (($r['status'] ?? '') === 'done') {
                $provisioned++;
            }
        }
        $out = "reconcile scanned={$scanned} provisioned={$provisioned}";
    } elseif ($job === 'recommend') {
        $out = 'recommend total=' . rebuild_all_recommendations();
    } elseif ($job === 'thankyou') {
        // 説明会終了後に「参加お礼＋意向確認」を自動送信（1人1回・重複防止）。
        $after = (int) env('SEMINAR_END_AFTER_SEC', '3600');
        $sent = 0;
        foreach (bookings_needing_thankyou($after) as $b) {
            $lu = (string) ($b['line_user_id'] ?? '');
            if ($lu === '') {
                continue;
            }
            if (!claim_thankyou((string) $b['id'])) {
                continue;
            }
            if (send_intent_check_to_contact($lu)) {
                set_line_contact_state($lu, 'seminar_done');
                $sent++;
            }
        }
        $out = "thankyou sent={$sent}";
    } elseif ($job === 'waiver') {
        // 紹介特典（月額無料化）の判定。active サブスク会員ごとに「アクティブな紹介先」を数え、
        // しきい値(既定5)以上で100%OFFクーポンを適用、割り込んだら解除。冪等。
        // 無料フェーズ（サブスク自体が無い期間）は Stripe を叩かずスキップ。
        if (!billing_started()) {
            $out = 'waiver skipped (free phase)';
        } else {
            init_stripe();
            $r = evaluate_referral_waiver();
            $out = "waiver mode={$r['mode']} scanned={$r['scanned']} applied={$r['applied']} removed={$r['removed']} errors={$r['errors']}";
        }
    } elseif ($job === 'seed') {
        // 開発用サンプル会員を投入（冪等）。確認後は unseed で削除する。
        $n = seed_sample_members();
        $out = "seed created={$n} total=" . sample_member_count();
    } elseif ($job === 'unseed') {
        $n = delete_sample_members();
        $out = "unseed deleted={$n}";
    } elseif ($job === 'diag') {
        // 運用診断（秘密情報は出力しない）。自己紹介ロック・LINE受信の状況を確認する。
        $L = [];
        $L[] = 'code_version=intro_gate_fix_2';
        $L[] = 'signup_mode=' . signup_mode();
        $L[] = 'intro_gate=' . (intro_gate_enabled() ? 'ON' : 'OFF');
        $L[] = 'line_token=' . (line_channel_token() !== null ? 'set' : 'MISSING');
        $L[] = 'line_secret=' . (line_channel_secret() !== null ? 'set' : 'MISSING');
        $q = static fn (string $sql): string => (string) db()->query($sql)->fetchColumn();
        $L[] = 'contacts=' . $q('SELECT COUNT(*) FROM line_contacts');
        $L[] = 'contacts_linked=' . $q('SELECT COUNT(*) FROM line_contacts WHERE member_id IS NOT NULL');
        $L[] = 'members_total=' . $q('SELECT COUNT(*) FROM members');
        $L[] = 'members_with_line=' . $q("SELECT COUNT(*) FROM members WHERE line_user_id IS NOT NULL AND line_user_id <> ''");
        $L[] = 'intro_done=' . $q('SELECT COUNT(*) FROM members WHERE intro_submitted_at IS NOT NULL AND intro_submitted_at > 0');
        $L[] = 'gated_now=' . $q("SELECT COUNT(*) FROM members WHERE line_user_id IS NOT NULL AND line_user_id <> '' AND (intro_submitted_at IS NULL OR intro_submitted_at = 0)");
        $L[] = 'inbound_24h=' . $q("SELECT COUNT(*) FROM line_messages WHERE direction = 'in' AND created_at > " . (time() - 86400));
        $L[] = 'inbound_7d=' . $q("SELECT COUNT(*) FROM line_messages WHERE direction = 'in' AND created_at > " . (time() - 7 * 86400));
        $last = $q("SELECT COALESCE(MAX(created_at), 0) FROM line_messages WHERE direction = 'in'");
        $L[] = 'last_inbound=' . ((int) $last > 0 ? gmdate('Y-m-d H:i', (int) $last + 9 * 3600) . ' JST' : 'none');
        $out = implode("\n", $L);
    } elseif ($job === 'samplephotos') {
        // サンプル会員の顔写真を強制的に割り当て直す（既存会員にも反映）。
        $n = attach_sample_photos(true);
        $out = "samplephotos set={$n}";
    }
} catch (\Throwable $e) {
    flock($lock, LOCK_UN);
    fclose($lock);
    http_response_code(500);
    error_log('cron error job=' . $job . ' ' . $e->getMessage());
    exit('error: ' . $e->getMessage() . "\n");
}

flock($lock, LOCK_UN);
fclose($lock);
echo '[' . gmdate('c') . "] {$out}\n";
