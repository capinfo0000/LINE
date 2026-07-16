<?php

/**
 * 運営者ダッシュボード（ログイン後のトップ）。
 * Stripe キーの設定状況、申込状況のグラフ、各管理へのリンクを表示する。
 * 集計は運営者自身の Stripe（事前=Checkoutセッション／当日=顧客）から行う。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
// 画面登録鍵→Connect→プラットフォームの順で Stripe 文脈を確立して集計。
$account = stripe_resolve_tenant($tenant);
$hasOwnKey = tenant_has_stripe_key($tenant);     // 画面で自分の鍵を登録済みか
$connected = $hasOwnKey || $account !== null;    // 自分の Stripe を使える状態か
$stripeReady = stripe_ready_for_tenant($tenant); // 名簿取得・決済が可能な構成か
$events = tenant_events($tenant['id']);
$usedEvents = count($events);
$publicUrl = base_url() . '/o.php?t=' . urlencode($tenant['id']);
$flash = (string) ($_GET['msg'] ?? '');
$flashType = (string) ($_GET['type'] ?? '');
$connectToken = csrf_token();

// ---- 申込状況の集計（自分の Stripe から。失敗・未設定でも画面は出す） ----
$byDate = [];          // 'Y-m-d' => 申込件数
$prepayCount = 0;
$onsiteCount = 0;
$collected = 0;        // 事前決済の入金（返金差引）合計
$onsiteDue = 0;        // 当日・未収合計
$statsError = false;
if ($stripeReady) {
    try {
        foreach ($events as $ev) {
            foreach (fetch_event_participants($ev['id'], $account) as $p) {
                $day = date('Y-m-d', (int) ($p['created'] ?? 0));
                $byDate[$day] = ($byDate[$day] ?? 0) + 1;
                if (($p['payment_type'] ?? 'prepay') === 'onsite') {
                    $onsiteCount++;
                    if (empty($p['collected'])) {
                        $onsiteDue += (int) $p['amount'];
                    }
                } else {
                    $prepayCount++;
                    $collected += max(0, (int) $p['amount'] - (int) $p['amount_refunded']);
                }
            }
        }
    } catch (\Throwable $e) {
        $statsError = true;
        error_log('ダッシュボード集計失敗: ' . $e->getMessage());
    }
}
$totalApplied = $prepayCount + $onsiteCount;

// 申込推移（日別の累積）をチャート用に整形
ksort($byDate);
$trendLabels = [];
$trendData = [];
$run = 0;
foreach ($byDate as $day => $cnt) {
    $run += $cnt;
    $trendLabels[] = substr($day, 5); // MM-DD
    $trendData[] = $run;
}

$pageTitle = 'ダッシュボード';
$pageSub = 'ようこそ、' . $tenant['display_name'] . ' さん';
$topActions = '<a class="btn" href="events.php">＋ イベントを作成</a>';
require __DIR__ . '/_app_header.php';
?>
<?php if ($flash !== ''): ?>
    <div class="flash <?= $flashType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($flash) ?></div>
<?php endif; ?>
<?php if (!$connected): ?>
    <div class="modal is-open" id="setupModal" role="dialog" aria-modal="true">
        <div class="modal__box">
            <button type="button" class="modal__close" data-modal-close aria-label="閉じる">×</button>
            <div class="modal__title">⚠️ Stripe が未設定です</div>
            <p>事前決済（クレジットカード）を受け付けるには、<strong>ご自身の Stripe API キー</strong>を登録してください。</p>
            <p class="muted">当日支払い（現金）のみのイベントは、設定なしでも利用できます。</p>
            <div class="modal__actions">
                <a class="btn" href="stripe.php">Stripe を設定する →</a>
                <button type="button" class="btn btn--ghost" data-modal-close>後で</button>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="stat-grid">
    <div class="stat"><span class="stat__num accent"><?= $totalApplied ?></span><span class="stat__label">総申込数（事前<?= $prepayCount ?>・当日<?= $onsiteCount ?>）</span></div>
    <div class="stat"><span class="stat__num"><?= e(format_amount($collected, 'jpy')) ?></span><span class="stat__label">事前入金合計</span></div>
    <div class="stat"><span class="stat__num"><?= e(format_amount($onsiteDue, 'jpy')) ?></span><span class="stat__label">当日・未収合計</span></div>
    <div class="stat"><span class="stat__num"><?= $usedEvents ?></span><span class="stat__label">登録イベント</span></div>
</div>

<div class="charts">
    <div class="card chart-card">
        <div class="card__title">申込推移（累計）</div>
        <?php if ($totalApplied > 0): ?>
            <div class="chart-box"><canvas id="chartTrend"></canvas></div>
        <?php else: ?>
            <div class="chart-empty"><?= $statsError ? 'Stripe から集計できませんでした。' : ($stripeReady ? 'まだ申込がありません。' : 'Stripe キーを設定すると申込状況が表示されます。') ?></div>
        <?php endif; ?>
    </div>
    <div class="card chart-card">
        <div class="card__title">支払い方法の内訳</div>
        <?php if ($totalApplied > 0): ?>
            <div class="chart-box"><canvas id="chartMethods"></canvas></div>
        <?php else: ?>
            <div class="chart-empty">データがありません</div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__title">Stripe（決済）</div>
    <?php if ($hasOwnKey): ?>
        <p>✅ あなたの Stripe API キーを登録済みです。参加費は<strong>あなた自身の Stripe アカウント</strong>へ直接入金され、名簿・決済データもあなたのアカウントで管理されます。</p>
        <p><a class="btn btn--ghost" href="stripe.php">Stripe 設定・接続テスト</a></p>
    <?php elseif (connect_enabled() && $account !== null): ?>
        <p>✅ あなたの Stripe アカウントを接続済みです（<code><?= e((string) $tenant['stripe_account_id']) ?></code>）。</p>
        <p><a class="btn btn--ghost" href="stripe.php">Stripe 設定</a></p>
    <?php else: ?>
        <p>⚠️ まだ Stripe を設定していません。<strong>ご自身の Stripe API キーを登録</strong>すると、参加費があなたの口座へ直接入金されます。</p>
        <p><a class="btn" href="stripe.php">Stripe を設定する</a></p>
        <p class="muted">未設定でも「当日支払い（現金）」のみのイベントは利用できます。</p>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__title">公開イベントページ</div>
    <p class="muted" style="margin-top:0;">この1つのリンクを参加者に共有すれば、開催中のイベントを一覧から選んで申し込めます。</p>
    <input type="text" class="js-select" readonly value="<?= e($publicUrl) ?>">
    <p style="margin: 16px 0 0;">
        <a class="btn" href="events.php">イベント管理</a>
        <a class="btn btn--ghost" href="index.php">参加者管理</a>
    </p>
</div>

<?php if ($totalApplied > 0): ?>
<script src="/assets/chart.umd.min.js"></script>
<script nonce="<?= e(csp_nonce()) ?>">
    const ACCENT = '#2563eb';
    const trendCtx = document.getElementById('chartTrend');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($trendLabels) ?>,
                datasets: [{
                    label: '累計申込',
                    data: <?= json_encode($trendData) ?>,
                    borderColor: ACCENT,
                    backgroundColor: 'rgba(37,99,235,.12)',
                    fill: true, tension: .35, pointRadius: 3, borderWidth: 2,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }
    const methodsCtx = document.getElementById('chartMethods');
    if (methodsCtx) {
        new Chart(methodsCtx, {
            type: 'doughnut',
            data: {
                labels: ['事前決済', '当日支払い'],
                datasets: [{
                    data: [<?= $prepayCount ?>, <?= $onsiteCount ?>],
                    backgroundColor: [ACCENT, '#f59e0b'],
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                cutout: '62%',
            },
        });
    }
</script>
<?php endif; ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
