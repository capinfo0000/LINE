<?php

/**
 * 申し込み者（公式LINEの友だち）の一覧・承認。
 *  - 無料フェーズ：「承認して発行」で決済なしの会員資格を発行し、LINEに配布する。
 *  - 課金フェーズ：「承認して案内」で決済リンクを送信する。
 * 承認処理は payment.php の approve_line_contact() がフェーズ判定して実行する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'approve') {
        $lu = (string) ($_POST['line_user_id'] ?? '');
        if ($lu === '' || find_line_contact($lu) === null) {
            $msg = 'LINE連絡先が見つかりません。';
            $msgType = 'ng';
        } else {
            try {
                $r = approve_line_contact($lu);
                $msg = $r['message'];
                $msgType = $r['ok'] ? 'ok' : 'ng';
            } catch (\Throwable $e) {
                error_log('approve_line_contact error: ' . $e->getMessage());
                $msg = '承認処理でエラーが発生しました。';
                $msgType = 'ng';
            }
        }
    } elseif ($action === 'delete_contact') {
        // 申込者（LINE連絡先）を完全削除。会員が紐付く場合は紐付けのみ解除（会員は残る）。
        $lu = (string) ($_POST['line_user_id'] ?? '');
        if (delete_line_contact($lu)) {
            $msg = '申込者を削除しました。';
        } else {
            $msg = 'LINE連絡先が見つかりませんでした。';
            $msgType = 'ng';
        }
    }
}

$billing = billing_started();
$stateLabels = [
    'added'            => '友だち追加',
    'booked_seminar'   => '説明会予約',
    'seminar_done'     => '説明会済み',
    'booked_interview' => '面談予約',
    'interview_done'   => '面談済み',
    'approved'         => '承認済み',
    'payment_sent'     => '決済案内済み',
    'paid'             => '入会済み',
];

$contacts = db()->query(
    'SELECT c.*, m.login_id AS member_login, m.status AS member_status
       FROM line_contacts c
       LEFT JOIN members m ON m.id = c.member_id
      ORDER BY (c.member_id IS NOT NULL) ASC, c.updated_at DESC
      LIMIT 300'
)->fetchAll();

$token = csrf_token();
$pageTitle = '申し込み者';
$pageSub = $billing ? '課金フェーズ：承認で決済リンクを送信' : '無料フェーズ：承認で無料入会を発行';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
    <p style="margin:.2rem 0;">
        <?php if ($billing): ?>
            <span class="badge" style="background:var(--ok-bg);color:var(--ok-fg);">課金フェーズ</span>
            「承認して案内」を押すと、その申込者へ<strong>決済リンク</strong>を送信します。入金後に会員資格が発行されます。
        <?php else: ?>
            <span class="badge badge--info">無料フェーズ</span>
            「承認して発行」を押すと、<strong>決済なしで会員資格（無料）</strong>を発行し、ログイン情報をLINEへ送信します。
        <?php endif; ?>
    </p>
</div>

<div class="card">
    <div class="card__title">申込者一覧（<?= count($contacts) ?>）</div>
    <?php if ($contacts === []): ?>
        <p class="muted">まだ申込者はいません。公式LINEに友だち追加されると、ここに表示されます。</p>
    <?php endif; ?>
    <?php foreach ($contacts as $c): ?>
        <?php
        $linked = !empty($c['member_id']);
        $state = (string) ($c['onboarding_state'] ?? 'added');
        $stateLabel = $stateLabels[$state] ?? $state;
        ?>
        <div style="border-bottom:1px solid var(--border);padding:10px 0;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <strong><?= e(($c['display_name'] ?? '') !== '' ? $c['display_name'] : '（名称未取得）') ?></strong>
                <span class="badge" style="background:#f1f5f9;color:#475569;"><?= e($stateLabel) ?></span>
                <?php if ($linked): ?>
                    <span class="badge" style="background:var(--ok-bg);color:var(--ok-fg);">会員: <?= e($c['member_login'] ?? '-') ?></span>
                <?php endif; ?>
                <br>
                <span class="muted" style="font-size:.82rem;">
                    <?= ($c['email'] ?? '') !== '' ? e($c['email']) : 'メール未取得' ?>
                    ・更新 <?= e(date('m/d H:i', (int) $c['updated_at'] + 9 * 3600)) ?>
                </span>
            </div>
            <div>
                <?php if ($linked): ?>
                    <span class="muted" style="font-size:.85rem;">発行済み</span>
                <?php else: ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="line_user_id" value="<?= e($c['line_user_id']) ?>">
                        <button type="submit" class="btn btn--sm" data-confirm="<?= $billing ? 'この申込者に決済リンクを送信しますか？' : 'この申込者に無料の会員資格を発行して送信しますか？' ?>">
                            <?= $billing ? '承認して案内' : '承認して発行' ?>
                        </button>
                    </form>
                <?php endif; ?>
                <form method="post" style="display:inline;margin-left:6px;">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                    <input type="hidden" name="action" value="delete_contact">
                    <input type="hidden" name="line_user_id" value="<?= e($c['line_user_id']) ?>">
                    <button type="submit" class="btn btn--ghost btn--sm" style="color:var(--dng);"
                            data-confirm="この申込者を完全に削除します（元に戻せません）。会員として発行済みの場合、会員アカウントは残りLINEの紐付けだけ解除されます。よろしいですか？">削除</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
