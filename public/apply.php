<?php

/**
 * 参加申込フォーム（自前）。氏名・連絡先・参加人数・備考を入力してもらい、
 * その内容を checkout.php へ渡して Stripe 決済へ進む。
 *
 * 【DBは持たない】入力内容は当サーバーに保存せず、Stripe の決済データ（metadata 等）
 * として渡して保管する。名簿は管理画面が Stripe から読み出して表示する。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$eventId = (string) ($_GET['event_id'] ?? '');
$event = find_event($eventId);

if ($event === null) {
    http_response_code(404);
    exit('指定されたイベントが見つかりません。');
}

// 濫用対策: 公開ページの連打（Stripe集計の増幅・偵察）を IP 単位で緩く制限する。
if (!rate_limit_check('view_apply', 120, 300)) {
    http_response_code(429);
    exit('アクセスが多すぎます。しばらくしてから再度お開きください。');
}

// 運営者の Stripe が使えるか（画面登録鍵 or Connect/プラットフォーム）。未設定でもフォームは表示。
$stripeReady = stripe_ready_for_event($event);
$account = stripe_resolve_event($event); // 残席計算で使う Stripe 文脈を確立

// 定員と残席（capacity>0 のとき）。取得に失敗しても申込は止めない。
$capacity = (int) ($event['capacity'] ?? 0);
$remaining = null; // null = 定員管理なし／不明
$isFull = false;
if ($capacity > 0 && $stripeReady) {
    try {
        $remaining = max(0, $capacity - event_headcount_cached($event['id'], $account));
        $isFull = ($remaining <= 0);
    } catch (\Throwable $e) {
        $remaining = null;
    }
}

$currency = $event['currency'] ?? 'jpy';
$prepayUnit = (int) ($event['amount'] ?? 0);
// 当日料金は未設定なら事前と同額
$onsiteUnit = isset($event['amount_onsite']) && $event['amount_onsite'] !== ''
    ? (int) $event['amount_onsite']
    : $prepayUnit;

// 受け付ける支払い方法（既存イベントには allow_* が無いので事前決済を既定で許可）
$allowPrepay = array_key_exists('allow_prepay', $event) ? !empty($event['allow_prepay']) : true;
$allowOnsite = !empty($event['allow_onsite']);
if (!$allowPrepay && !$allowOnsite) {
    $allowPrepay = true; // 念のため最低1つは有効に
}
$defaultMethod = $allowPrepay ? 'prepay' : 'onsite';
$defaultUnit = $defaultMethod === 'prepay' ? $prepayUnit : $onsiteUnit;

// 参加人数の上限: capacity があればそれ、無ければ 10 を目安に
$maxParty = (int) ($event['capacity'] ?? 0);
if ($maxParty < 1) {
    $maxParty = 10;
}
$maxParty = min($maxParty, 20);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>参加申込 - <?= e($event['name'] ?? '') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
    <style nonce="<?= e(csp_nonce()) ?>">
        .pay-options { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
        .pay-options label { font-weight: 400; display: flex; gap: 8px; align-items: center; margin: 0; }
        .pay-options input[type=radio] { width: auto; }
    </style>
</head>
<body>
<div class="container">
    <div class="brandbar">イベント参加申込</div>
    <h1><?= e($event['name'] ?? '') ?></h1>
    <div class="card">
        <p class="muted"><?= e($event['date'] ?? '') ?>　<?= e($event['place'] ?? '') ?></p>
        <p><?= e($event['description'] ?? '') ?></p>
        <p class="muted">
            <?php if ($allowPrepay): ?>事前決済：<strong><?= e(format_amount($prepayUnit, $currency)) ?></strong> / 1名<?php endif; ?>
            <?php if ($allowPrepay && $allowOnsite): ?>　／　<?php endif; ?>
            <?php if ($allowOnsite): ?>当日支払い：<strong><?= e(format_amount($onsiteUnit, $currency)) ?></strong> / 1名<?php endif; ?>
        </p>
        <?php if ($capacity > 0 && $remaining !== null): ?>
            <p class="muted">定員 <?= $capacity ?> 名　<?= $isFull ? '<strong style="color:#dc2626;">満員</strong>' : '残り <strong>' . $remaining . '</strong> 名' ?></p>
        <?php endif; ?>
    </div>

    <?php if ($isFull): ?>
        <div class="card"><p style="font-weight:700; color:#dc2626;">申し訳ありません。定員に達したため、受付を終了しました。</p></div>
    <?php else: ?>
    <form action="checkout.php" method="post" class="card">
        <input type="hidden" name="event_id" value="<?= e($event['id']) ?>">

        <label for="name">お名前 <span class="req">必須</span></label>
        <input type="text" id="name" name="name" required maxlength="100" autocomplete="name" placeholder="山田 太郎">

        <label for="email">メールアドレス <span class="req">必須</span></label>
        <input type="email" id="email" name="email" required maxlength="200" autocomplete="email" placeholder="taro@example.com">

        <label for="phone">電話番号</label>
        <input type="tel" id="phone" name="phone" maxlength="30" autocomplete="tel" placeholder="090-1234-5678">

        <label for="party_size">参加人数（ご本人を含む） <span class="req">必須</span></label>
        <select id="party_size" name="party_size" required>
            <?php for ($i = 1; $i <= $maxParty; $i++): ?>
                <option value="<?= $i ?>"><?= $i ?> 名</option>
            <?php endfor; ?>
        </select>

        <label for="note">備考（アレルギー・ご要望など）</label>
        <textarea id="note" name="note" maxlength="500" placeholder="例：エビ・カニアレルギーあり"></textarea>

        <label>お支払い方法 <span class="req">必須</span></label>
        <div class="pay-options">
            <?php if ($allowPrepay): ?>
                <label style="font-weight:400; display:flex; gap:8px; align-items:center; width:auto;">
                    <input type="radio" name="payment_type" value="prepay" <?= $defaultMethod === 'prepay' ? 'checked' : '' ?> style="width:auto;">
                    事前決済（今すぐカード等で前払い・<?= e(format_amount($prepayUnit, $currency)) ?>/名）
                </label>
            <?php endif; ?>
            <?php if ($allowOnsite): ?>
                <label style="font-weight:400; display:flex; gap:8px; align-items:center; width:auto;">
                    <input type="radio" name="payment_type" value="onsite" <?= $defaultMethod === 'onsite' ? 'checked' : '' ?> style="width:auto;">
                    当日支払い（会場で集金・<?= e(format_amount($onsiteUnit, $currency)) ?>/名）
                </label>
            <?php endif; ?>
        </div>

        <p class="total">お支払い合計：<span id="total"><?= e(format_amount($defaultUnit, $currency)) ?></span> <span id="total-note" class="hint" style="margin:0;"></span></p>

        <?= captcha_widget_html() ?>
        <?php $blockedInit = (!$stripeReady && $defaultMethod === 'prepay'); ?>
        <button type="submit" class="btn btn--block btn--lg" id="submitBtn" <?= $blockedInit ? 'disabled' : '' ?>><?= $defaultMethod === 'onsite' ? 'この内容で申し込む（当日支払い）→' : '事前決済する →' ?></button>
        <?php if (!$stripeReady): ?>
            <p class="notice" id="prepayBlockNote" style="<?= $blockedInit ? '' : 'display:none;' ?>">⚠️ 現在この主催者は支払い口座の設定が完了していないため、<strong>事前決済（オンライン前払い）</strong>は利用できません。<?= $allowOnsite ? '「当日支払い」を選んでお申し込みください。' : '準備が整うまでお待ちください。' ?></p>
        <?php endif; ?>
        <p class="hint" id="methodNote"></p>
        <p class="hint">キャンセル時の返金は<a href="policy.php?event_id=<?= e($event['id']) ?>" target="_blank">キャンセルポリシー</a>をご確認ください。</p>
        <?php if ($allowPrepay): ?>
            <p class="hint"><button type="button" class="btn btn--ghost" data-modal-open="prepayInfo">事前決済（カード）の安全性について</button></p>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <p class="muted"><a href="index.php">← トップへ戻る</a></p>
</div>

<?php require __DIR__ . '/_prepay_info_modal.php'; ?>

    <script nonce="<?= e(csp_nonce()) ?>">
        // 支払い方法・参加人数に応じて合計金額と案内文を更新（計算の正は決済時にサーバー側で再確定）
        const PREPAY_UNIT = <?= $prepayUnit ?>;
        const ONSITE_UNIT = <?= $onsiteUnit ?>;
        const CURRENCY = <?= json_encode(strtolower((string) $currency)) ?>;
        const STRIPE_READY = <?= $stripeReady ? 'true' : 'false' ?>;
        function formatAmount(total) {
            if (CURRENCY === 'jpy') {
                return '¥' + total.toLocaleString('ja-JP');
            }
            return (total / 100).toFixed(2) + ' ' + CURRENCY.toUpperCase();
        }
        function selectedMethod() {
            const el = document.querySelector('input[name="payment_type"]:checked');
            return el ? el.value : 'prepay';
        }
        function updateTotal() {
            const ps = document.getElementById('party_size');
            if (!ps) return; // 満員などでフォーム非表示のとき
            const qty = parseInt(ps.value, 10) || 1;
            const method = selectedMethod();
            const unit = method === 'onsite' ? ONSITE_UNIT : PREPAY_UNIT;
            document.getElementById('total').textContent = formatAmount(unit * qty);

            const btn = document.getElementById('submitBtn');
            const note = document.getElementById('methodNote');
            const totalNote = document.getElementById('total-note');
            if (method === 'onsite') {
                btn.textContent = 'この内容で申し込む（当日支払い）→';
                totalNote.textContent = '（当日、会場でお支払い）';
                note.textContent = '申込を受け付けます。当日、会場で上記金額をお支払いください。今はお支払いは発生しません。';
            } else {
                btn.textContent = '事前決済する →';
                totalNote.textContent = '';
                note.textContent = '「事前決済する」を押すと、安全な Stripe の決済画面に移動します。カード情報は主催者には渡りません。';
            }

            // 事前決済は主催者の支払い口座連携が必須。未連携時は事前決済のみ無効化し、当日支払いは許可。
            const blocked = (method === 'prepay' && !STRIPE_READY);
            btn.disabled = blocked;
            const blockNote = document.getElementById('prepayBlockNote');
            if (blockNote) {
                blockNote.style.display = blocked ? '' : 'none';
            }
        }
        // インライン属性の代わりにここでイベントを束ねる（CSP厳格化対応）
        document.addEventListener('DOMContentLoaded', function () {
            const ps = document.getElementById('party_size');
            if (ps) { ps.addEventListener('change', updateTotal); }
            document.querySelectorAll('input[name="payment_type"]').forEach(function (r) {
                r.addEventListener('change', updateTotal);
            });
            updateTotal();
        });
    </script>
</body>
</html>
