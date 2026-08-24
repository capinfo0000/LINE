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
        <label style="margin-top:0;">カバー画像（背景・全会員に公開／横長推奨）</label>
        <?php if ($coverAbs !== null): ?>
            <img src="/member/photo.php?kind=cover" alt="現在のカバー画像" style="width:100%;height:130px;object-fit:cover;border-radius:14px;box-shadow:var(--shadow-sm);margin-top:4px;">
            <div style="margin-top:6px;"><label style="font-weight:normal;display:inline-flex;gap:6px;align-items:center;"><input type="checkbox" name="cover_delete" value="1" style="width:auto;"> 削除する</label></div>
        <?php endif; ?>
        <input type="file" name="cover" accept="image/jpeg,image/png,image/webp">

        <label>顔写真</label>
        <?php if ($photoAbs !== null): ?>
            <img src="/member/photo.php" alt="現在の写真" style="width:110px;height:110px;object-fit:cover;border-radius:16px;box-shadow:var(--shadow-md);margin-top:4px;">
            <div style="margin-top:6px;"><label style="font-weight:normal;display:inline-flex;gap:6px;align-items:center;"><input type="checkbox" name="photo_delete" value="1" style="width:auto;"> 削除する</label></div>
        <?php endif; ?>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">

        <label>名刺画像（全会員に公開）</label>
        <?php if ($cardAbs !== null): ?>
            <img src="/member/photo.php?kind=card" alt="現在の名刺画像" style="max-width:100%;border-radius:12px;box-shadow:var(--shadow-sm);margin-top:4px;">
            <div style="margin-top:6px;"><label style="font-weight:normal;display:inline-flex;gap:6px;align-items:center;"><input type="checkbox" name="card_delete" value="1" style="width:auto;"> 削除する</label></div>
        <?php endif; ?>
        <input type="file" name="card" accept="image/jpeg,image/png,image/webp">
    </div>

    <div class="card">
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
        </div>
    </div>

    <div class="card">
        <div class="card__title" style="color:var(--coral-d);">リンク・表示設定</div>
        <p class="muted" style="margin-top:0;font-size:.85rem;">1行目は「LINE追加URL」推奨。空欄の行は無視されます。</p>
        <?php
        // 既存＋空行を最大6行表示
        $rows = $links;
        while (count($rows) < 6) {
            $rows[] = ['kind' => count($rows) === 0 ? 'line_add' : 'other', 'label' => '', 'url' => ''];
        }
        foreach ($rows as $i => $lk): ?>
            <div style="display:flex;gap:6px;margin-bottom:6px;flex-wrap:wrap;">
                <select name="link_kind[]" style="max-width:130px;">
                    <option value="line_add"<?= ($lk['kind'] ?? '') === 'line_add' ? ' selected' : '' ?>>LINE追加</option>
                    <option value="other"<?= ($lk['kind'] ?? '') !== 'line_add' ? ' selected' : '' ?>>その他</option>
                </select>
                <input type="text" name="link_label[]" placeholder="ラベル(任意)" value="<?= e($lk['label'] ?? '') ?>" style="max-width:130px;">
                <input type="url" name="link_url[]" placeholder="https://..." value="<?= e($lk['url'] ?? '') ?>" style="flex:1;min-width:180px;">
            </div>
        <?php endforeach; ?>

        <div style="border-top:1px solid var(--border);margin:14px 0 0;padding-top:14px;">
            <label style="font-weight:normal;"><input type="checkbox" name="vis_directory" value="1"<?= $vis['directory'] ? ' checked' : '' ?>> 会員ディレクトリに自分を掲載する</label><br>
            <label style="font-weight:normal;"><input type="checkbox" name="vis_line_url" value="1"<?= $vis['line_url'] ? ' checked' : '' ?>> LINE追加URLを他の会員に表示する</label>
        </div>
    </div>

    <p><button type="submit" class="btn">保存する</button>
       <a class="btn btn--ghost" href="/member/dashboard.php">戻る</a></p>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
