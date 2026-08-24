<?php

/**
 * 会員プロフィール編集。
 * タグ選択（場所/仕事/目的/提供）＋自由記述＋リンク複数＋顔写真＋求める条件＋表示制御。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();
$memberId = $member['id'];
$msg = '';
$msgType = 'ok';

$grouped = all_tags_grouped();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    // 本文＋表示制御（年齢は生年月日から自動算出。生年月日は非公開）
    save_profile($memberId, [
        'name_text'     => (string) ($_POST['name_text'] ?? ''),
        'birthdate'     => (string) ($_POST['birthdate'] ?? ''),
        'company_title' => (string) ($_POST['company_title'] ?? ''),
        'headline'      => (string) ($_POST['headline'] ?? ''),
        'bio'           => (string) ($_POST['bio'] ?? ''),
        'visibility'    => [
            'directory' => !empty($_POST['vis_directory']),
            'line_url'  => !empty($_POST['vis_line_url']),
        ],
    ]);

    // タグ（全カテゴリの選択を集約）
    $tagIds = array_map('intval', (array) ($_POST['tags'] ?? []));
    set_member_tags($memberId, $tagIds);

    // 求める条件
    save_preferences(
        $memberId,
        array_map('intval', (array) ($_POST['seek_area'] ?? [])),
        array_map('intval', (array) ($_POST['seek_job'] ?? [])),
        array_map('intval', (array) ($_POST['seek_purpose'] ?? []))
    );

    // リンク（LINE追加URL＋任意リンク）
    $kinds = (array) ($_POST['link_kind'] ?? []);
    $labels = (array) ($_POST['link_label'] ?? []);
    $urls = (array) ($_POST['link_url'] ?? []);
    $links = [];
    foreach ($urls as $i => $u) {
        $links[] = ['kind' => (string) ($kinds[$i] ?? 'other'), 'label' => (string) ($labels[$i] ?? ''), 'url' => (string) $u];
    }
    set_member_links($memberId, $links);

    // 顔写真：削除 or アップロード
    if (!empty($_POST['photo_delete'])) {
        delete_member_photo($memberId);
    } elseif (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $perr = '';
        if (!save_member_photo($memberId, $_FILES['photo'], $perr)) {
            $msg = $perr;
            $msgType = 'ng';
        }
    }

    // カバー画像（全会員公開）：削除 or アップロード
    if (!empty($_POST['cover_delete'])) {
        delete_member_image($memberId, 'cover_path');
    } elseif (($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $cerr = '';
        if (!save_member_image($memberId, 'cover_path', 'cover', $_FILES['cover'], 1200, $cerr)) {
            $msg = $cerr;
            $msgType = 'ng';
        }
    }

    // 名刺画像（全会員公開）：削除 or アップロード
    if (!empty($_POST['card_delete'])) {
        delete_member_image($memberId, 'card_path');
    } elseif (($_FILES['card']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $kerr = '';
        if (!save_member_image($memberId, 'card_path', 'card', $_FILES['card'], 1000, $kerr)) {
            $msg = $kerr;
            $msgType = 'ng';
        }
    }

    if ($msg === '') {
        $msg = 'プロフィールを保存しました。';
    }
    audit_log('member.profile_save', ['member' => $memberId]);
}

// 現在値
$profile = get_profile($memberId);
$vis = profile_visibility($profile);
$myTagIds = array_flip(get_member_tag_ids($memberId));
$prefs = get_preferences($memberId);
$prefArea = array_flip($prefs['seek_area']);
$prefJob = array_flip($prefs['seek_job']);
$prefPurpose = array_flip($prefs['seek_purpose']);
$links = get_member_links($memberId);
$photoAbs = member_photo_abs_path($profile);
$coverAbs = member_image_abs_path($profile, 'cover_path');
$cardAbs = member_image_abs_path($profile, 'card_path');
$birthdate = (string) ($profile['birthdate'] ?? '');
$currentAge = $birthdate !== '' ? compute_age($birthdate) : null;

$token = csrf_token();
$pageTitle = 'プロフィール編集';
$showLogout = true;
$hideBrand = true;
require __DIR__ . '/_header.php';

/** チェックボックス群を描画するヘルパー（data-group で行サマリと連動）。 */
$renderChecks = function (array $tags, string $name, array $checked, string $group = '') {
    if ($tags === []) {
        return;
    }
    $g = $group !== '' ? ' data-group="' . e($group) . '"' : '';
    echo '<div class="chips">';
    foreach ($tags as $t) {
        $id = (int) $t['id'];
        $isC = isset($checked[$id]);
        echo '<label class="chip">'
            . '<input type="checkbox" name="' . e($name) . '[]" value="' . $id . '"' . ($isC ? ' checked' : '') . $g . '>'
            . '<span>' . e($t['label']) . '</span></label>';
    }
    echo '</div>';
};

/** tapple風の「行タップ→ポップアップで選択」ピッカー（行＋モーダル）を出力する。 */
$renderPicker = function (string $modalId, string $rowLabel, array $tags, string $name, array $checked, string $group) use ($renderChecks) {
    $sel = [];
    foreach ($tags as $t) {
        if (isset($checked[(int) $t['id']])) {
            $sel[] = (string) $t['label'];
        }
    }
    $summary = $sel !== [] ? implode('、', $sel) : '未選択';
    ?>
    <button type="button" class="tp-field" data-modal-open="<?= e($modalId) ?>">
        <span class="tp-field__l"><?= e($rowLabel) ?></span>
        <span class="tp-field__v<?= $sel === [] ? ' is-empty' : '' ?>" data-summary="<?= e($group) ?>"><?= e($summary) ?></span>
        <span class="tp-field__c">›</span>
    </button>
    <div class="modal" id="<?= e($modalId) ?>">
        <div class="modal__box">
            <button type="button" class="modal__close" data-modal-close aria-label="閉じる">×</button>
            <div class="modal__title"><?= e($rowLabel) ?></div>
            <p class="modal__lead">当てはまるものを選んでください（複数可）</p>
            <?php $renderChecks($tags, $name, $checked, $group); ?>
            <div class="modal__actions"><button type="button" class="btn" data-modal-close>決定</button></div>
        </div>
    </div>
    <?php
};

/** テキスト/日付の「行タップ→ポップアップ編集」フィールド。$isAge=true は日付入力で行に年齢を表示。 */
$renderField = function (string $modalId, string $label, string $key, string $name, string $rawValue, string $displayValue, string $inputType = 'text', string $placeholder = '', bool $isAge = false, array $attrs = []) {
    $empty = $displayValue === '';
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . $k . '="' . e((string) $v) . '"';
    }
    ?>
    <button type="button" class="tp-field" data-modal-open="<?= e($modalId) ?>">
        <span class="tp-field__l"><?= e($label) ?></span>
        <span class="tp-field__v<?= $empty ? ' is-empty' : '' ?>" data-fieldval="<?= e($key) ?>"><?= $empty ? '未設定' : e($displayValue) ?></span>
        <span class="tp-field__c">›</span>
    </button>
    <div class="modal" id="<?= e($modalId) ?>">
        <div class="modal__box">
            <button type="button" class="modal__close" data-modal-close aria-label="閉じる">×</button>
            <div class="modal__title"><?= e($label) ?></div>
            <input type="<?= e($inputType) ?>" name="<?= e($name) ?>" value="<?= e($rawValue) ?>" data-field="<?= e($key) ?>"<?= $isAge ? ' data-field-age' : '' ?> placeholder="<?= e($placeholder) ?>"<?= $extra ?>>
            <div class="modal__actions"><button type="button" class="btn" data-modal-close>決定</button></div>
        </div>
    </div>
    <?php
};

/** テキストエリア（自己紹介など）の「行タップ→ポップアップ編集」フィールド。 */
$renderTextareaField = function (string $modalId, string $label, string $key, string $name, string $value, string $placeholder = '') {
    $empty = trim($value) === '';
    $preview = $empty ? '' : mb_substr(preg_replace('/\s+/u', ' ', $value), 0, 24);
    ?>
    <button type="button" class="tp-field" data-modal-open="<?= e($modalId) ?>">
        <span class="tp-field__l"><?= e($label) ?></span>
        <span class="tp-field__v<?= $empty ? ' is-empty' : '' ?>" data-fieldval="<?= e($key) ?>"><?= $empty ? '未設定' : e($preview) ?></span>
        <span class="tp-field__c">›</span>
    </button>
    <div class="modal" id="<?= e($modalId) ?>">
        <div class="modal__box">
            <button type="button" class="modal__close" data-modal-close aria-label="閉じる">×</button>
            <div class="modal__title"><?= e($label) ?></div>
            <textarea name="<?= e($name) ?>" data-field="<?= e($key) ?>" rows="6" maxlength="2000" placeholder="<?= e($placeholder) ?>" style="width:100%;"><?= e($value) ?></textarea>
            <div class="modal__actions"><button type="button" class="btn" data-modal-close>決定</button></div>
        </div>
    </div>
    <?php
};

/** リンク1行（種別・ラベル・URL）を描画する。追加行のテンプレートでも使用。 */
$renderLinkRow = function (array $lk = ['kind' => 'other', 'label' => '', 'url' => '']) {
    ?>
    <div class="tp-linkrow-edit" style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;">
        <select name="link_kind[]" style="max-width:120px;">
            <option value="line_add"<?= ($lk['kind'] ?? '') === 'line_add' ? ' selected' : '' ?>>LINE追加</option>
            <option value="other"<?= ($lk['kind'] ?? '') !== 'line_add' ? ' selected' : '' ?>>その他</option>
        </select>
        <input type="text" name="link_label[]" placeholder="ラベル(任意)" value="<?= e($lk['label'] ?? '') ?>" style="max-width:120px;">
        <input type="url" name="link_url[]" placeholder="https://..." value="<?= e($lk['url'] ?? '') ?>" style="flex:1;min-width:170px;">
    </div>
    <?php
};
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 6px;">
    <h1 style="font-size:1.5rem;margin:0;">プロフィール編集</h1>
    <a class="btn btn--ghost" href="/member/member_view.php?id=<?= e($memberId) ?>" style="padding:8px 16px;border-radius:999px;">👁 プレビュー</a>
</div>
<p class="muted" style="margin:0 0 14px;"><a href="/member/dashboard.php">← マイページ</a></p>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <div class="card">
        <!-- imgpipe: <?= e(photo_pipeline_tag()) ?> -->
        <?php
        $camSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>';
        ?>
        <!-- カバー＋顔写真（インスタ風エディタ） -->
        <div class="tp-editcover">
            <label class="tp-cov">
                <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" hidden>
                <?php if ($coverAbs !== null): ?><img src="/member/photo.php?kind=cover" alt="カバー画像"><?php endif; ?>
                <span class="tp-cam"><?= $camSvg ?> カバー画像</span>
            </label>
            <label class="tp-avedit">
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden>
                <?php if ($photoAbs !== null): ?><img src="/member/photo.php" alt="顔写真"><?php else: ?><span class="tp-avedit__ph"><?= $camSvg ?></span><?php endif; ?>
                <span class="tp-cam-badge"><?= $camSvg ?></span>
            </label>
        </div>
        <?php if ($coverAbs !== null || $photoAbs !== null): ?>
        <div class="tp-imgdel">
            <?php if ($photoAbs !== null): ?><label><input type="checkbox" name="photo_delete" value="1"> 顔写真を削除</label><?php endif; ?>
            <?php if ($coverAbs !== null): ?><label><input type="checkbox" name="cover_delete" value="1"> カバーを削除</label><?php endif; ?>
        </div>
        <?php endif; ?>

        <label style="margin-top:16px;">名刺画像（全会員に公開）</label>
        <label class="tp-cardedit">
            <input type="file" name="card" accept="image/jpeg,image/png,image/webp" hidden>
            <?php if ($cardAbs !== null): ?><img src="/member/photo.php?kind=card" alt="名刺画像"><?php else: ?><span class="tp-cardedit__ph"><?= $camSvg ?> 名刺画像を選ぶ</span><?php endif; ?>
            <?php if ($cardAbs !== null): ?><span class="tp-cam"><?= $camSvg ?> 変更</span><?php endif; ?>
        </label>
        <?php if ($cardAbs !== null): ?>
        <div class="tp-imgdel"><label><input type="checkbox" name="card_delete" value="1"> 名刺を削除</label></div>
        <?php endif; ?>

        <hr style="border:0;border-top:1px solid var(--border);margin:18px 0;">
        <div class="tp-fields">
            <?php $renderField('m-name', '名前', 'f-name', 'name_text', (string) $profile['name_text'], (string) $profile['name_text'], 'text', '例: 田中 由紀', false, ['maxlength' => '100']); ?>
            <?php $renderField('m-birth', '生年月日（年齢のみ公開）', 'f-birth', 'birthdate', $birthdate, $currentAge !== null ? $currentAge . '歳' : '', 'date', '', true, ['min' => '1900-01-01', 'max' => date('Y-m-d')]); ?>
            <?php $renderField('m-company', '会社・肩書き', 'f-company', 'company_title', (string) $profile['company_title'], (string) $profile['company_title'], 'text', '例: 田中会計事務所 / 代表', false, ['maxlength' => '120']); ?>
            <?php $renderField('m-headline', 'ひとことPR', 'f-headline', 'headline', (string) $profile['headline'], (string) $profile['headline'], 'text', '例: 補助金・資金繰りが専門です', false, ['maxlength' => '120']); ?>
            <?php $renderTextareaField('m-bio', '自己紹介', 'f-bio', 'bio', (string) $profile['bio'], '経歴・得意なこと・どんな方とつながりたいか など'); ?>
            <?php $renderPicker('m-area', '📍 場所', $grouped['area'] ?? [], 'tags', $myTagIds, 'gt-area'); ?>
            <?php $renderPicker('m-job', '💼 仕事ジャンル', $grouped['job'] ?? [], 'tags', $myTagIds, 'gt-job'); ?>
            <?php $renderPicker('m-purpose', '🎯 目的（求めること）', $grouped['purpose'] ?? [], 'tags', $myTagIds, 'gt-purpose'); ?>
            <?php $renderPicker('m-offer', '🤝 提供できること', $grouped['offer'] ?? [], 'tags', $myTagIds, 'gt-offer'); ?>
            <?php $renderPicker('m-sarea', '相手の場所（求める条件）', $grouped['area'] ?? [], 'seek_area', $prefArea, 'gt-sarea'); ?>
            <?php $renderPicker('m-sjob', '相手の仕事ジャンル（求める条件）', $grouped['job'] ?? [], 'seek_job', $prefJob, 'gt-sjob'); ?>
            <?php $renderPicker('m-spurpose', '相手の目的（求める条件）', $grouped['purpose'] ?? [], 'seek_purpose', $prefPurpose, 'gt-spurpose'); ?>
            <?php
            // リンク：基本情報と同じ行スタイル。件数サマリを表示し、タップでポップアップ編集。
            $linkCount = 0;
            foreach ($links as $lk) {
                if (trim((string) ($lk['url'] ?? '')) !== '') {
                    $linkCount++;
                }
            }
            ?>
            <button type="button" class="tp-field" data-modal-open="m-links">
                <span class="tp-field__l">リンク</span>
                <span class="tp-field__v<?= $linkCount === 0 ? ' is-empty' : '' ?>" id="linkSummary"><?= $linkCount > 0 ? $linkCount . '件' : '未設定' ?></span>
                <span class="tp-field__c">›</span>
            </button>
        </div>

        <hr style="border:0;border-top:1px solid var(--border);margin:18px 0;">
        <div class="card__title" style="color:var(--coral-d);margin-bottom:8px;">表示設定</div>
        <label style="font-weight:normal;"><input type="checkbox" name="vis_directory" value="1"<?= $vis['directory'] ? ' checked' : '' ?>> 会員ディレクトリに掲載する</label>
        <p class="muted" style="font-size:.8rem;margin:2px 0 10px 24px;">OFFにすると、他の会員の「さがす」や検索に表示されません（非公開）。</p>
        <label style="font-weight:normal;"><input type="checkbox" name="vis_line_url" value="1"<?= $vis['line_url'] ? ' checked' : '' ?>> LINE追加URLを他の会員に表示する</label>
        <p class="muted" style="font-size:.8rem;margin:2px 0 0 24px;">上の「リンク」で登録したLINE追加URLを、プロフィールで他の会員に見せます。</p>
    </div>

    <!-- リンク編集モーダル（自分で件数を追加できる） -->
    <div class="modal" id="m-links">
        <div class="modal__box">
            <button type="button" class="modal__close" data-modal-close aria-label="閉じる">×</button>
            <div class="modal__title">リンク</div>
            <p class="modal__lead">LINE追加URL・SNS・Webなど。1行目は「LINE追加」推奨。空欄の行は無視されます。</p>
            <div id="linkRows">
                <?php
                $rows = $links;
                if ($rows === []) {
                    $rows[] = ['kind' => 'line_add', 'label' => '', 'url' => ''];
                }
                foreach ($rows as $lk) {
                    $renderLinkRow($lk);
                }
                ?>
            </div>
            <p style="margin:4px 0 0;"><button type="button" class="btn btn--ghost" data-clone="linkRowTpl" data-clone-into="linkRows">＋ リンクを追加</button></p>
            <div class="modal__actions"><button type="button" class="btn" data-modal-close>決定</button></div>
        </div>
    </div>
    <template id="linkRowTpl"><?php $renderLinkRow(); ?></template>

    <p><button type="submit" class="btn">保存する</button>
       <a class="btn btn--ghost" href="/member/dashboard.php">戻る</a></p>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
