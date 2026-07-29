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

    // 本文＋表示制御
    save_profile($memberId, [
        'name_text'     => (string) ($_POST['name_text'] ?? ''),
        'age_text'      => (string) ($_POST['age_text'] ?? ''),
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

$token = csrf_token();
$pageTitle = 'プロフィール編集';
$showLogout = true;
require __DIR__ . '/_header.php';

/** チェックボックス群を描画するヘルパー。 */
$renderChecks = function (array $tags, string $name, array $checked) {
    foreach ($tags as $t) {
        $id = (int) $t['id'];
        $isC = isset($checked[$id]);
        echo '<label style="display:inline-block;margin:2px 8px 2px 0;font-weight:normal;">'
            . '<input type="checkbox" name="' . e($name) . '[]" value="' . $id . '"' . ($isC ? ' checked' : '') . '> '
            . e($t['label']) . '</label>';
    }
};
?>
<h1>プロフィール編集</h1>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <div class="card">
        <div class="card__title">基本情報</div>
        <label>名前</label>
        <input type="text" name="name_text" maxlength="100" value="<?= e($profile['name_text']) ?>">
        <label>年齢</label>
        <input type="text" name="age_text" maxlength="40" value="<?= e($profile['age_text']) ?>" placeholder="例: 30代 / 35歳">
        <label>会社名・屋号／肩書き</label>
        <input type="text" name="company_title" maxlength="120" value="<?= e($profile['company_title']) ?>">
        <label>ひとことPR（一覧に表示される見出し）</label>
        <input type="text" name="headline" maxlength="120" value="<?= e($profile['headline']) ?>">
        <label>自己紹介</label>
        <textarea name="bio" rows="5" maxlength="2000"><?= e($profile['bio']) ?></textarea>
    </div>

    <div class="card">
        <div class="card__title">顔写真</div>
        <?php if ($photoAbs !== null): ?>
            <p><img src="/member/photo.php" alt="現在の写真" style="max-width:140px;border-radius:10px;"></p>
            <label style="font-weight:normal;"><input type="checkbox" name="photo_delete" value="1"> この写真を削除する</label>
        <?php endif; ?>
        <label>写真をアップロード（JPEG/PNG/WebP）</label>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-max-dim="1024">
        <p class="muted">大きな写真は送信前に自動で縮小して送信します。正方形に切り抜いて表示されます。</p>
    </div>

    <div class="card">
        <div class="card__title">タグ（あなたの属性）</div>
        <?php foreach (['area' => '場所', 'job' => '仕事ジャンル', 'purpose' => '目的（求めること）', 'offer' => '提供できること'] as $cat => $label): ?>
            <p style="margin-bottom:4px;"><strong><?= e($label) ?></strong></p>
            <div style="margin-bottom:12px;"><?php $renderChecks($grouped[$cat] ?? [], 'tags', $myTagIds); ?></div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card__title">求める条件（おすすめ・検索に使用。未選択＝問わない）</div>
        <p style="margin-bottom:4px;"><strong>相手の場所</strong></p>
        <div style="margin-bottom:12px;"><?php $renderChecks($grouped['area'] ?? [], 'seek_area', $prefArea); ?></div>
        <p style="margin-bottom:4px;"><strong>相手の仕事ジャンル</strong></p>
        <div style="margin-bottom:12px;"><?php $renderChecks($grouped['job'] ?? [], 'seek_job', $prefJob); ?></div>
        <p style="margin-bottom:4px;"><strong>相手の目的</strong></p>
        <div style="margin-bottom:12px;"><?php $renderChecks($grouped['purpose'] ?? [], 'seek_purpose', $prefPurpose); ?></div>
    </div>

    <div class="card">
        <div class="card__title">リンク（LINE追加URL・SNS・Web）</div>
        <p class="muted" style="margin-top:0;">1行目は「LINE追加URL」を推奨します。空欄の行は無視されます（最大10件）。</p>
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
    </div>

    <div class="card">
        <div class="card__title">表示設定</div>
        <label style="font-weight:normal;"><input type="checkbox" name="vis_directory" value="1"<?= $vis['directory'] ? ' checked' : '' ?>> 会員ディレクトリに自分を掲載する</label><br>
        <label style="font-weight:normal;"><input type="checkbox" name="vis_line_url" value="1"<?= $vis['line_url'] ? ' checked' : '' ?>> LINE追加URLを他の会員に表示する</label>
    </div>

    <p><button type="submit" class="btn">保存する</button>
       <a class="btn btn--ghost" href="/member/dashboard.php">戻る</a></p>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
