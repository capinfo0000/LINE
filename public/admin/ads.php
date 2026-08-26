<?php

/**
 * 広告枠の管理。画像とリンク先、掲載場所と期間を運営が決める。
 *
 * 外部の広告配信（AdSense 等）は使っていない。外部のスクリプトを許可すると
 * CSP を緩めることになり、これまでの対策が弱くなるため。ここに入るのは
 * 運営が用意した画像だけで、配信も自前のサーバから行う。
 *
 * 削除は元に戻せないので、他の完全削除と同じくプラットフォーム管理者のみ。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$isAdmin = (int) ($tenant['is_admin'] ?? 0) === 1;
$msg = (string) ($_GET['msg'] ?? '');
$msgType = ((string) ($_GET['type'] ?? 'ok')) === 'ng' ? 'ng' : 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if (!$isAdmin) {
            audit_log('authz.admin_deny', ['tenant' => $tenant['id'], 'path' => 'admin/ads.delete']);
            header('Location: ads?msg=' . rawurlencode('広告の削除にはプラットフォーム管理者権限が必要です。') . '&type=ng');
            exit;
        }
        $ok = ad_delete($id);
        $note = $ok ? '広告を削除しました。' : '対象の広告が見つかりませんでした。';
        $type = $ok ? 'ok' : 'ng';
    } elseif ($action === 'toggle_premium') {
        $to = app_setting_get('ads_free_premium', '0') === '1' ? '0' : '1';
        app_setting_set('ads_free_premium', $to);
        audit_log('admin.ads_free_premium', ['on' => $to]);
        $note = $to === '1' ? 'プレミアム会員には広告を出さない設定にしました。' : 'プレミアム会員にも広告を出す設定にしました。';
        $type = 'ok';
    } elseif ($action === 'toggle_all') {
        ads_set_enabled(!ads_enabled());
        $note = ads_enabled() ? '広告の表示をONにしました。' : '広告の表示をOFFにしました（登録内容はそのまま残っています）。';
        $type = 'ok';
    } elseif ($action === 'toggle') {
        $ad = find_ad($id);
        if ($ad === null) {
            $note = '対象の広告が見つかりませんでした。';
            $type = 'ng';
        } else {
            $to = (int) $ad['is_active'] === 1 ? 0 : 1;
            db()->prepare('UPDATE ads SET is_active = ?, updated_at = ? WHERE id = ?')->execute([$to, time(), $id]);
            audit_log('admin.ad_toggle', ['id' => $id, 'active' => $to]);
            $note = $to === 1 ? '掲載を開始しました。' : '掲載を止めました。';
            $type = 'ok';
        }
    } else {
        $r = ad_save($id, $_POST, $_FILES);
        $note = $r['message'];
        $type = $r['ok'] ? 'ok' : 'ng';
    }
    header('Location: ads?msg=' . rawurlencode($note) . '&type=' . $type);
    exit;
}

$rows = all_ads();
$slots = ad_slots();
$token = csrf_token();
$pageTitle = '広告';
$pageSub = '会員画面に出す広告（' . count($rows) . '件）';
require __DIR__ . '/_app_header.php';

/** 掲載中かどうかを、期間と有効フラグから判定する。 */
$liveState = static function (array $a): array {
    $now = time();
    if ((int) $a['is_active'] !== 1) {
        return ['止めている', 'mute'];
    }
    if (((string) ($a['image_path'] ?? '')) === '') {
        return ['画像がありません', 'mute'];
    }
    if ($a['starts_at'] !== null && (int) $a['starts_at'] > $now) {
        return ['開始前', 'mute'];
    }
    if ($a['ends_at'] !== null && (int) $a['ends_at'] < $now) {
        return ['期間終了', 'mute'];
    }
    return ['掲載中', 'info'];
};

/** 入力欄（新規追加と編集で同じ形を使う）。 */
$renderForm = static function (array $a, array $slots, string $token, bool $isNew, bool $isAdmin, callable $liveState): void {
    $id = (int) ($a['id'] ?? 0);
    $slot = (string) ($a['slot'] ?? 'feed');
    $ymd = static fn ($ts): string => $ts === null || (int) $ts === 0 ? '' : date('Y-m-d', (int) $ts);
    ?>
    <form method="post" enctype="multipart/form-data" class="card">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="card__title">
            <?php if ($isNew): ?>
                広告を追加
            <?php else: ?>
                <?php [$label, $badge] = $liveState($a); ?>
                広告 #<?= $id ?>
                <span class="badge badge--<?= e($badge) ?>"><?= e($label) ?></span>
                <span class="muted" style="font-weight:400;font-size:.82rem;">
                    表示 <?= number_format((int) $a['impressions']) ?> 回 ／ クリック <?= number_format((int) $a['clicks']) ?> 回
                </span>
            <?php endif; ?>
        </div>

        <?php if (!$isNew && ($abs = ad_image_abs_path($a)) !== null): ?>
            <?php $sz = @getimagesize($abs); ?>
            <p class="hint" style="margin:0 0 8px;">
                いまの画像：<?= (int) ($sz[0] ?? 0) ?>×<?= (int) ($sz[1] ?? 0) ?>px（<?= round(filesize($abs) / 1024) ?>KB）
            </p>
        <?php endif; ?>

        <label>管理用の名前（必須・会員には表示されません）</label>
        <input type="text" name="title" maxlength="100" required value="<?= e((string) ($a['title'] ?? '')) ?>" placeholder="例: 提携店クーポン 2026年9月">

        <label>掲載場所</label>
        <select name="slot" required>
            <?php foreach ($slots as $k => $s): ?>
                <option value="<?= e($k) ?>"<?= $slot === $k ? ' selected' : '' ?>><?= e($s['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="hint" style="margin:4px 0 0;">
            <?php foreach ($slots as $k => $s): ?>
                <strong><?= e($s['label']) ?></strong>：<?= e($s['hint']) ?><br>
            <?php endforeach; ?>
        </p>

        <label>画像<?= $isNew ? '（必須）' : '（変えたいときだけ選んでください）' ?></label>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp"<?= $isNew ? ' required' : '' ?>>
        <p class="hint" style="margin:4px 0 0;">JPEG / PNG / WebP、5MBまで。サーバ側で作り直して保存します（縦型は幅320px、横型は幅1200pxまで縮小）。</p>

        <label>リンク先（空にすると、押せない画像として出ます）</label>
        <input type="url" name="url" maxlength="300" value="<?= e((string) ($a['url'] ?? '')) ?>" placeholder="https://example.com/campaign">
        <p class="hint" style="margin:4px 0 0;">https:// で始まるURL、またはサイト内のパス（/member/… の形）。</p>

        <label>画像の説明（読み上げ・画像が出ないときに使われます）</label>
        <input type="text" name="alt" maxlength="120" value="<?= e((string) ($a['alt'] ?? '')) ?>" placeholder="例: 〇〇カフェ 会員限定10%オフ">

        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1 1 140px;">
                <label>掲載開始（空=すぐ）</label>
                <input type="date" name="starts_at" value="<?= e($ymd($a['starts_at'] ?? null)) ?>">
            </div>
            <div style="flex:1 1 140px;">
                <label>掲載終了（空=無期限）</label>
                <input type="date" name="ends_at" value="<?= e($ymd($a['ends_at'] ?? null)) ?>">
            </div>
            <div style="flex:1 1 140px;">
                <label>出やすさ（1〜100）</label>
                <input type="number" name="weight" min="1" max="100" value="<?= (int) ($a['weight'] ?? 1) ?>">
            </div>
        </div>
        <p class="hint" style="margin:4px 0 0;">同じ場所に複数あるときは、この数字が大きいものが出やすくなります（1つだけなら関係ありません）。</p>

        <p style="margin-top:10px;">
            <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;">
                <input type="checkbox" name="is_active" value="1"<?= $isNew || (int) ($a['is_active'] ?? 0) === 1 ? ' checked' : '' ?>>
                掲載する
            </label>
        </p>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
            <button type="submit" class="btn"><?= $isNew ? '追加する' : '保存する' ?></button>
            <?php if (!$isNew): ?>
                <button type="submit" name="action" value="toggle" formnovalidate class="btn btn--ghost">
                    <?= (int) $a['is_active'] === 1 ? '掲載を止める' : '掲載を開始する' ?>
                </button>
                <?php if ($isAdmin): ?>
                    <button type="submit" name="action" value="delete" formnovalidate class="btn btn--danger"
                            data-confirm="この広告を削除します。画像も消えます。元に戻せません。よろしいですか？">削除</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </form>
    <?php
};
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
    <div class="card__title" style="margin:0;">
        広告の表示
        <span class="badge badge--<?= ads_enabled() ? 'info' : 'mute' ?>"><?= ads_enabled() ? 'ON' : 'OFF' ?></span>
    </div>
    <p class="hint" style="margin:.6rem 0 0;">
        <?php if (ads_enabled()): ?>
            いま広告は会員画面に出ています。OFFにすると、<strong>下の登録内容はそのまま残したまま</strong>、
            すべての広告が出なくなります。
        <?php else: ?>
            いま広告は<strong>どこにも出ていません</strong>。下の個別の設定に関係なく、全部止まっています。
            ONにすると、掲載中にしてある広告が元どおり出ます。
        <?php endif; ?>
    </p>
    <form method="post" style="margin:.8rem 0 0;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="toggle_all">
        <button type="submit" class="btn<?= ads_enabled() ? ' btn--ghost' : '' ?>"
                data-confirm="<?= ads_enabled() ? '広告の表示をすべてOFFにします。よろしいですか？（登録内容は残ります）' : '広告の表示をONにします。よろしいですか？' ?>">
            <?= ads_enabled() ? '広告をすべてOFFにする' : '広告をONにする' ?>
        </button>
    </form>
</div>

<div class="card">
    <?php $__prem = app_setting_get('ads_free_premium', '0') === '1'; ?>
    <div class="card__title" style="margin:0;">
        追加料金で広告を非表示にする
        <span class="badge badge--<?= (string) (env('STRIPE_PRICE_ID_ADFREE', '') ?? '') !== '' ? 'info' : 'mute' ?>">
            <?= (string) (env('STRIPE_PRICE_ID_ADFREE', '') ?? '') !== '' ? '販売中' : '未設定' ?>
        </span>
    </div>
    <p class="hint" style="margin:.6rem 0 0;">
        会員は「マイページ → 広告の非表示」から<strong><?= (int) adfree_purchase_days() ?>日間</strong>の期間券を買えます。
        買い切り（自動更新なし）で、期限は購入するたびに足されます。<br>
        <?php if ((string) (env('STRIPE_PRICE_ID_ADFREE', '') ?? '') === ''): ?>
            <strong>いまは販売していません。</strong>Stripeで一回払いの価格を作り、
            「初期設定」の <code>STRIPE_PRICE_ID_ADFREE</code> に入れると購入できるようになります。
            設定するまで、会員側に購入の導線は出ません。
        <?php else: ?>
            価格は Stripe 側の設定に従います。金額を変えるときは Stripe の価格を差し替えてください。
        <?php endif; ?><br>
        個別の会員に無料で付けたい場合は、<strong>会員詳細</strong>から日数を指定して付与できます。
    </p>
    <form method="post" style="margin:.8rem 0 0;">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="toggle_premium">
        <button type="submit" class="btn btn--ghost btn--sm">
            <?= $__prem ? 'プレミアム会員にも広告を出す' : 'プレミアム会員には広告を出さない' ?>
        </button>
        <span class="muted" style="font-size:.82rem;margin-left:8px;">
            いま：<?= $__prem ? 'プレミアムには出さない' : '全員に出す' ?>
        </span>
    </form>
    <?php if ($__prem && !billing_started()): ?>
        <p class="flash flash--ng" style="margin:.8rem 0 0;">
            いまは無料フェーズで<strong>全員がプレミアム相当</strong>のため、この設定がONだと
            <strong>誰にも広告が出ません</strong>。課金フェーズに入るまではOFFを推奨します。
        </p>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__title" style="margin:0;">この画面でできること</div>
    <p class="hint" style="margin:.6rem 0 0;">
        会員画面に出す広告を登録します。<strong>画像は運営が用意したものだけ</strong>で、外部の広告配信は使っていません。<br>
        <strong>PCの左右（縦型）</strong>は、画面幅が1520px以上のときだけ出ます。1280〜1440pxのノートPCでは、
        一覧の見やすさを守るため出しません。2件登録すると左右に1枚ずつ、1件なら右だけに出ます。<br>
        <strong>さがすの一覧の中</strong>は、スマホでもPCでも会員カードの4件目の後ろに出ます。
        どちらの枠も、右上に「広告」と表示されます。
    </p>
</div>

<?php foreach ($slots as $slotKey => $slotDef): ?>
    <?php $inSlot = array_values(array_filter($rows, static fn ($r) => (string) $r['slot'] === $slotKey)); ?>
    <div class="card" style="background:#f9fafb;">
        <div class="card__title" style="margin:0;"><?= e($slotDef['label']) ?>（<?= count($inSlot) ?>件）</div>
        <p class="hint" style="margin:.4rem 0 0;"><?= e($slotDef['hint']) ?></p>
    </div>
    <?php if ($inSlot === []): ?>
        <div class="card"><p style="margin:0;">まだ登録がありません。</p></div>
    <?php else: ?>
        <?php foreach ($inSlot as $a): ?>
            <?php $renderForm($a, $slots, $token, false, $isAdmin, $liveState); ?>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endforeach; ?>

<?php $renderForm([], $slots, $token, true, $isAdmin, $liveState); ?>
<?php require __DIR__ . '/_app_footer.php'; ?>
