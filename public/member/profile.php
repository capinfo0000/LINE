<?php

/**
 * 会員プロフィール編集。
 * タグ選択（場所/仕事/目的/提供）＋自由記述＋リンク複数＋顔写真＋求める条件＋表示制御。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$member = require_member();
$memberId = $member['id'];
$msg = '';
$msgType = 'ok';

$grouped = all_tags_grouped();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    // 入力チェック：問題があれば「何がダメで・どうすればいいか」を明示する。
    $errors = [];
    $inBirth = trim((string) ($_POST['birthdate'] ?? ''));
    if ($inBirth !== '' && normalize_birthdate($inBirth) === '') {
        $errors[] = '生年月日が正しくありません。カレンダーから実在する日付を選び直してください（例: 1990-05-20）。';
    } elseif ($inBirth !== '' && !is_eligible_birthdate(normalize_birthdate($inBirth))) {
        $errors[] = '本サービスは満' . member_min_age() . '歳以上の方のみご利用いただけます。生年月日をご確認ください。';
    }
    if (trim((string) ($_POST['name_text'] ?? '')) === '') {
        $errors[] = '名前が未入力です。他の会員に表示される名前を入力してください。';
    }
    foreach ((array) ($_POST['link_url'] ?? []) as $u) {
        $u = trim((string) $u);
        if ($u !== '' && !is_valid_link_url($u)) {
            $errors[] = 'リンクのURLが正しくありません。「https://」から始まる正しいURLを入力してください（例: https://example.com）。';
            break;
        }
    }

    // 画像は本文の入力チェックより先に保存する。
    // ファイル選択は画面を作り直すと必ず消えるため（ブラウザの仕様）、
    // 「名前が未入力」などで保存を中断すると、選んだ画像だけが黙って捨てられていた。
    // 画像は名前や生年月日とは独立して保存できるので、ここで先に確定させる。
    $imgMsgs = [];
    $imgDeleted = [];
    /** この送信で画像が1つでも添付されていたか。 */
    $hasUpload = static function (): bool {
        foreach (['photo', 'cover', 'card'] as $k) {
            if (($_FILES[$k]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                return true;
            }
        }
        return false;
    };
    $uploaded = $hasUpload();

    /**
     * 画像1つ分の受け取り。
     *  ・error が OK なら保存する
     *  ・error が NO_FILE（未選択）なら、削除指定だけを見る
     *  ・それ以外（上限超過など）は理由を返す
     *    ここを取りこぼすと「保存しました」と出るのに画像だけ入らない、
     *    原因の分からない不具合になる。
     */
    $takeImage = static function (
        string $field,
        string $label,
        callable $save,
        callable $delete
    ) use (&$imgMsgs, &$imgDeleted): void {
        // 「削除する」が押されていれば、選ばれているファイルより削除を優先する。
        // 押した本人の意図は削除なので、添付があっても取り込まない。
        if (!empty($_POST[$field . '_delete'])) {
            $delete();
            $imgDeleted[] = $label;
            return;
        }
        $code = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_OK) {
            $err = '';
            if (!$save($_FILES[$field], $err)) {
                $imgMsgs[] = $label . '：' . $err;
            }
            return;
        }
        if ($code !== UPLOAD_ERR_NO_FILE) {
            $imgMsgs[] = $label . '：' . upload_error_message($code);
        }
    };

    $takeImage(
        'photo',
        '顔写真',
        static fn (array $f, string &$e): bool => save_member_photo($memberId, $f, $e),
        static function () use ($memberId): void { delete_member_photo($memberId); }
    );
    $takeImage(
        'cover',
        'カバー画像',
        static fn (array $f, string &$e): bool => save_member_image($memberId, 'cover_path', 'cover', $f, 1200, $e),
        static function () use ($memberId): void { delete_member_image($memberId, 'cover_path'); }
    );
    $takeImage(
        'card',
        '名刺画像',
        static fn (array $f, string &$e): bool => save_member_image($memberId, 'card_path', 'card', $f, 1000, $e),
        static function () use ($memberId): void { delete_member_image($memberId, 'card_path'); }
    );

    if ($errors !== []) {
        // 本文は保存せずにエラーを表示（入力はそのまま残す）。
        // 画像は上で保存済みなので、その旨も伝える（選び直させないため）。
        $done = [];
        if ($imgDeleted !== []) {
            $done[] = implode('と', $imgDeleted) . 'を削除しました。';
        }
        if ($imgMsgs === [] && $uploaded && $imgDeleted === []) {
            $done[] = '選んだ画像は保存しました。';
        }
        $msg = implode("\n", array_merge($imgMsgs, $errors));
        if ($done !== []) {
            $msg .= "\n※ " . implode(' ', $done) . '上の項目を直して、もう一度「保存」を押してください。';
        }
        $msgType = 'ng';
        goto render; // 本文の保存処理をスキップ
    }

    // 本文＋表示制御（年齢は生年月日から自動算出。生年月日は非公開）
    save_profile($memberId, [
        'name_text'  => (string) ($_POST['name_text'] ?? ''),
        'birthdate'  => (string) ($_POST['birthdate'] ?? ''),
        'occupation' => (string) ($_POST['occupation'] ?? ''),
        'headline'   => (string) ($_POST['headline'] ?? ''),
        'bio'        => (string) ($_POST['bio'] ?? ''),
        // 表示設定は常にON（ディレクトリ掲載・LINE追加URL表示）で固定。
        'visibility' => ['directory' => true, 'line_url' => true],
    ]);

    // タグ（全カテゴリの選択を集約）
    $tagIds = array_map('intval', (array) ($_POST['tags'] ?? []));
    set_member_tags($memberId, $tagIds);

    // ※「相手の求める条件」はプロフィールから廃止（検索で探す方針）。既存設定は変更しない。

    // リンク（LINE追加URL＋任意リンク）
    $kinds = (array) ($_POST['link_kind'] ?? []);
    $labels = (array) ($_POST['link_label'] ?? []);
    $urls = (array) ($_POST['link_url'] ?? []);
    $links = [];
    foreach ($urls as $i => $u) {
        $links[] = ['kind' => (string) ($kinds[$i] ?? 'other'), 'label' => (string) ($labels[$i] ?? ''), 'url' => (string) $u];
    }
    set_member_links($memberId, $links);

    if ($imgMsgs !== []) {
        // 画像だけ失敗した場合。本文は保存できているので、その旨も伝える。
        $msg = implode("\n", $imgMsgs) . "\nそれ以外の内容は保存しました。";
        $msgType = 'ng';
    } elseif ($imgDeleted !== []) {
        // 何が起きたか分かるように、削除したものを名前で伝える。
        $msg = implode('と', $imgDeleted) . 'を削除しました。';
    } else {
        $msg = 'プロフィールを保存しました。';
    }
    audit_log('member.profile_save', ['member' => $memberId]);
}

render:
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

// 検証エラーで保存をスキップした場合は、DB値ではなく「送信された内容」で再表示する
// （ユーザーが打ち込んだ変更を失わせない）。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $msgType === 'ng') {
    $profile['name_text']  = (string) ($_POST['name_text'] ?? $profile['name_text']);
    $profile['occupation'] = (string) ($_POST['occupation'] ?? ($profile['occupation'] ?? ''));
    $profile['headline']   = (string) ($_POST['headline'] ?? $profile['headline']);
    $profile['bio']        = (string) ($_POST['bio'] ?? $profile['bio']);
    $birthdate  = (string) ($_POST['birthdate'] ?? $birthdate);
    $currentAge = normalize_birthdate($birthdate) !== '' ? compute_age($birthdate) : null;
    $myTagIds   = array_flip(array_map('intval', (array) ($_POST['tags'] ?? [])));
    $pk = (array) ($_POST['link_kind'] ?? []);
    $pl = (array) ($_POST['link_label'] ?? []);
    $pu = (array) ($_POST['link_url'] ?? []);
    $links = [];
    foreach ($pu as $i => $u) {
        if (trim((string) $u) === '' && trim((string) ($pl[$i] ?? '')) === '') {
            continue;
        }
        $links[] = ['kind' => (string) ($pk[$i] ?? 'other'), 'label' => (string) ($pl[$i] ?? ''), 'url' => (string) $u];
    }
}

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
    <h1 style="font-size:1.5rem;margin:0;white-space:nowrap;">プロフィール編集</h1>
    <a class="btn btn--ghost" href="<?= e(member_public_path($member)) ?>" style="padding:8px 16px;border-radius:999px;flex:0 0 auto;width:auto;white-space:nowrap;">プレビュー</a>
</div>
<p class="muted" style="margin:0 0 14px;"><a href="/member/dashboard">← マイページ</a></p>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= nl2br(e($msg)) ?></div><?php endif; ?>

<div class="card">
    <div class="card__title">プロフィールの共有URL</div>
    <p class="hint" style="margin:0 0 8px;">
        このURLを送ると、あなたのプロフィールを見てもらえます（相手も会員としてログインしている必要があります）。
    </p>
    <div class="tp-share">
        <code id="shareUrl" class="tp-share__url"><?= e(member_public_url($member)) ?></code>
        <button type="button" class="btn btn--ghost" data-copy-target="shareUrl"
                data-copied-label="コピーしました">コピー</button>
    </div>
</div>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <div class="card">
        <!-- imgpipe: <?= e(photo_pipeline_tag()) ?> -->
        <?php
        $camSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>';
        $imgV = (int) ($profile['updated_at'] ?? 0); // キャッシュバスター（保存直後に即反映）
        ?>
        <!-- カバー＋顔写真。押すと選択画面（モーダル）が開く。
             ファイル選択と削除はモーダルの中にまとめてある。 -->
        <div class="tp-editcover">
            <button type="button" class="tp-cov" data-modal-open="m-cover" data-thumb="cover" aria-label="カバー画像を変更">
                <?php if ($coverAbs !== null): ?><img src="/member/photo?kind=cover&v=<?= $imgV ?>" alt="カバー画像"><?php endif; ?>
                <span class="tp-cam"><?= $camSvg ?> カバー画像</span>
            </button>
            <button type="button" class="tp-avedit" data-modal-open="m-photo" data-thumb="photo" aria-label="顔写真を変更">
                <?php if ($photoAbs !== null): ?><img src="/member/photo?v=<?= $imgV ?>" alt="顔写真"><?php else: ?><span class="tp-avedit__ph"><?= $camSvg ?></span><?php endif; ?>
            </button>
        </div>

        <hr style="border:0;border-top:1px solid var(--border);margin:18px 0;">
        <div class="tp-fields">
            <?php $renderField('m-name', '名前', 'f-name', 'name_text', (string) $profile['name_text'], (string) $profile['name_text'], 'text', '例: 田中 由紀', false, ['maxlength' => '100']); ?>
            <?php $renderField('m-birth', '生年月日（年齢のみ公開）', 'f-birth', 'birthdate', $birthdate, $currentAge !== null ? $currentAge . '歳' : '', 'date', '', true, ['min' => '1900-01-01', 'max' => date('Y-m-d', strtotime('-' . member_min_age() . ' years'))]); ?>
            <?php $renderField('m-occ', '職業', 'f-occ', 'occupation', (string) ($profile['occupation'] ?? ''), (string) ($profile['occupation'] ?? ''), 'text', '例: 税理士 / Webエンジニア / 飲食店オーナー', false, ['maxlength' => '80']); ?>
            <?php $renderField('m-headline', 'ひとことPR', 'f-headline', 'headline', (string) $profile['headline'], (string) $profile['headline'], 'text', '例: 補助金・資金繰りが専門です', false, ['maxlength' => '120']); ?>
            <?php $renderTextareaField('m-bio', '自己紹介', 'f-bio', 'bio', (string) $profile['bio'], '経歴・得意なこと・どんな方とつながりたいか など'); ?>
            <?php $renderPicker('m-area', '場所', $grouped['area'] ?? [], 'tags', $myTagIds, 'gt-area'); ?>
            <?php $renderPicker('m-job', '仕事ジャンル', $grouped['job'] ?? [], 'tags', $myTagIds, 'gt-job'); ?>
            <?php $renderPicker('m-purpose', '目的（求めること）', $grouped['purpose'] ?? [], 'tags', $myTagIds, 'gt-purpose'); ?>
            <?php $renderPicker('m-offer', '提供できること', $grouped['offer'] ?? [], 'tags', $myTagIds, 'gt-offer'); ?>
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
            <button type="button" class="tp-field" data-modal-open="m-card">
                <span class="tp-field__l">名刺画像</span>
                <span class="tp-field__v<?= $cardAbs === null ? ' is-empty' : '' ?>"><?= $cardAbs === null ? '未設定' : '登録済み' ?></span>
                <span class="tp-field__c">›</span>
            </button>
        </div>
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

    <?php
    /**
     * 画像の選択画面（モーダル）。写真を選ぶ枠と、登録済みなら削除ボタンを出す。
     *
     * 削除は送信ボタンにして、サーバ側の <field>_delete の受け口をそのまま使う。
     * formnovalidate を付けるのは、名前が未入力でも削除できるようにするため
     * （HTML5の入力チェックで送信が止まると、サーバに届かず消せない）。
     */
    $renderImageModal = static function (
        string $id,
        string $field,
        string $title,
        string $lead,
        ?string $abs,
        string $imgUrl,
        string $pickLabel,
        string $camSvg
    ): void {
        ?>
        <div class="modal" id="<?= e($id) ?>">
            <div class="modal__box">
                <button type="button" class="modal__close" data-modal-close aria-label="閉じる">×</button>
                <div class="modal__title"><?= e($title) ?></div>
                <p class="modal__lead"><?= e($lead) ?></p>
                <label class="tp-cardedit" data-thumb-for="<?= e($field) ?>">
                    <input type="file" name="<?= e($field) ?>" accept="image/jpeg,image/png,image/webp" hidden>
                    <?php if ($abs !== null): ?>
                        <img src="<?= e($imgUrl) ?>" alt="<?= e($title) ?>"><span class="tp-cam"><?= $camSvg ?> 変更</span>
                    <?php else: ?>
                        <span class="tp-cardedit__ph"><?= $camSvg ?> <?= e($pickLabel) ?></span>
                    <?php endif; ?>
                </label>
                <div class="modal__actions">
                    <button type="button" class="btn" data-modal-close>決定</button>
                    <?php if ($abs !== null): ?>
                        <button type="submit" name="<?= e($field) ?>_delete" value="1" formnovalidate
                                class="btn btn--danger"
                                data-confirm="<?= e($title . 'を削除します。元に戻せません。よろしいですか？') ?>">削除する</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    };
    ?>

    <!-- カバー画像 選択モーダル -->
    <?php $renderImageModal(
        'm-cover',
        'cover',
        'カバー画像',
        'プロフィール上部に出る横長の画像です。全会員に公開されます。',
        $coverAbs,
        '/member/photo?kind=cover&v=' . $imgV,
        'カバー画像を選ぶ',
        $camSvg
    ); ?>

    <!-- 顔写真 選択モーダル -->
    <?php $renderImageModal(
        'm-photo',
        'photo',
        '顔写真',
        '会員一覧やプロフィールに出ます。公開したくない場合は登録しなくても利用できます。',
        $photoAbs,
        '/member/photo?v=' . $imgV,
        '顔写真を選ぶ',
        $camSvg
    ); ?>

    <!-- 名刺画像 選択モーダル -->
    <?php $renderImageModal(
        'm-card',
        'card',
        '名刺画像',
        '全会員に公開されます。個人情報の記載にご注意ください。',
        $cardAbs,
        '/member/photo?kind=card&v=' . $imgV,
        '名刺画像を選ぶ',
        $camSvg
    ); ?>

    <!-- 画像を選んだときだけ表示される未保存の注意（app.js が表示を切り替える） -->
    <div id="unsavedNotice" class="flash flash--ng" hidden>
        画像を選択しました。<strong>まだ保存されていません。</strong>下の「保存する」を押すと確定します。
    </div>
    <p><button type="submit" class="btn">保存する</button>
       <a class="btn btn--ghost" href="/member/dashboard">戻る</a></p>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
