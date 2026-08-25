<?php

/**
 * 規約・ポリシーの本文を編集する管理画面。
 * 保存値は app_settings（legal_* キー）に入り、公開ページ（terms/privacy/policy）へ反映される。
 * 差し込み語（{事業者名} など）は表示時に置き換わるので、各種設定や運用モードの
 * 切り替えに文面が追従したまま編集できる。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();
$defs = legal_doc_defs();
$msg = (string) ($_GET['msg'] ?? '');
$msgType = ((string) ($_GET['type'] ?? 'ok')) === 'ng' ? 'ng' : 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $doc = (string) ($_POST['doc'] ?? '');

    if (!isset($defs[$doc])) {
        header('Location: /admin/settings_legal.php?msg=' . rawurlencode('対象の文書が不明です。もう一度お試しください。') . '&type=ng');
        exit;
    }

    if ($action === 'reset') {
        legal_body_reset($doc);
        audit_log('admin.legal_reset', ['doc' => $doc]);
        $note = $defs[$doc]['label'] . 'を既定の文面に戻しました。';
    } else {
        $body = (string) ($_POST['body'] ?? '');
        if (trim($body) === '') {
            header('Location: /admin/settings_legal.php?msg=' . rawurlencode('本文が空です。空欄では保存できません（既定に戻す場合は「既定の文面に戻す」を押してください）。') . '&type=ng');
            exit;
        }
        legal_body_save($doc, $body);
        audit_log('admin.legal_saved', ['doc' => $doc, 'len' => mb_strlen($body)]);
        $note = $defs[$doc]['label'] . 'を保存しました。公開ページに反映されます。'
            . (legal_body_is_default($doc) ? '（既定の文面と同じ内容だったため、既定に追従する状態に戻しました）' : '');
    }
    header('Location: /admin/settings_legal.php?msg=' . rawurlencode($note) . '&type=ok');
    exit;
}

$token = csrf_token();
$pageTitle = '規約・ポリシー';
$pageSub = '利用規約・プライバシーポリシー・返金ポリシーの本文';
require __DIR__ . '/_app_header.php';
?>
<?php if ($msg !== ''): ?><div class="flash <?= $msgType === 'ok' ? 'flash--ok' : 'flash--ng' ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="card">
    <div class="card__title">書き方</div>
    <p class="hint" style="margin:0 0 10px;">
        HTMLは使えません。次の記号だけで見出しや箇条書きになります。
    </p>
    <table style="width:100%;border-collapse:collapse;font-size:.86rem;">
        <tr><td style="padding:4px 8px 4px 0;white-space:nowrap;"><code># 見出し</code></td><td>見出しになります</td></tr>
        <tr><td style="padding:4px 8px 4px 0;white-space:nowrap;"><code>- 項目</code></td><td>箇条書き（・）になります</td></tr>
        <tr><td style="padding:4px 8px 4px 0;white-space:nowrap;"><code>1. 項目</code></td><td>番号付きの箇条書きになります</td></tr>
        <tr><td style="padding:4px 8px 4px 0;white-space:nowrap;"><code>**強調**</code></td><td>太字になります</td></tr>
        <tr><td style="padding:4px 8px 4px 0;white-space:nowrap;"><code>[表示文字](/policy.php)</code></td><td>リンクになります</td></tr>
        <tr><td style="padding:4px 8px 4px 0;white-space:nowrap;"><code>空行</code></td><td>段落の区切りになります</td></tr>
    </table>
</div>

<div class="card">
    <div class="card__title">差し込み語（自動で埋まる部分）</div>
    <p class="hint" style="margin:0 0 10px;">
        本文にそのまま書いておくと、表示時に下の内容へ置き換わります。<br>
        <strong>各種設定を変えたときや、運用モードのON/OFFを切り替えたときに、規約の文面も自動で追従します。</strong>
        手で書き換えてしまうと追従しなくなるので、変わりうる箇所はこの差し込み語のままにしておくのがおすすめです。
    </p>
    <div class="table-wrap">
        <table style="width:100%;min-width:560px;border-collapse:collapse;font-size:.86rem;">
            <tr><th style="text-align:left;">差し込み語</th><th style="text-align:left;">今の表示内容</th></tr>
            <?php foreach (legal_tokens() as $tk => $val): ?>
                <tr>
                    <td style="vertical-align:top;padding:6px 10px 6px 0;"><code><?= e($tk) ?></code></td>
                    <td style="padding:6px 0;"><?= $val !== '' ? e($val) : '<span class="muted">（今は空。この語を書いた行と、その見出しは表示されません）</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td style="vertical-align:top;padding:6px 10px 6px 0;"><code>{条}</code></td>
                <td style="padding:6px 0;">第1条・第2条…（表示される見出しの順に自動で採番。条文が増減しても番号がずれません）</td>
            </tr>
        </table>
    </div>
</div>

<?php foreach ($defs as $key => $def): ?>
    <?php $isDefault = legal_body_is_default($key); ?>
    <div class="card">
        <div class="card__title">
            <?= e($def['label']) ?>
            <?php if ($isDefault): ?>
                <span class="badge badge--mute">既定の文面</span>
            <?php else: ?>
                <span class="badge badge--info">編集済み</span>
            <?php endif; ?>
        </div>
        <p class="hint" style="margin:0 0 8px;">
            <a href="<?= e($def['page']) ?>" target="_blank" rel="noopener">公開ページを開く →</a>
        </p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="doc" value="<?= e($key) ?>">
            <textarea name="body" rows="18" maxlength="20000" spellcheck="false"
                      style="width:100%;font-size:.88rem;line-height:1.7;font-family:var(--font);"><?= e(legal_body($key)) ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                <button type="submit" class="btn">保存する</button>
                <?php if (!$isDefault): ?>
                    <button type="submit" class="btn btn--ghost" name="action" value="reset"
                            data-confirm="編集した内容を破棄して、既定の文面に戻します。よろしいですか？">既定の文面に戻す</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
<?php endforeach; ?>

<div class="card">
    <div class="card__title">特定商取引法に基づく表記について</div>
    <p class="hint" style="margin:0;">
        特商法のページは項目が決まっているため、ここではなく<a href="/admin/settings_site.php">各種設定</a>の事業者情報から入力します。
        入力すると<a href="/tokushoho.php" target="_blank" rel="noopener">特商法ページ</a>に反映され、上の規約・ポリシーの <code>{事業者名}</code> 等にも同じ値が入ります。
    </p>
</div>
<?php require __DIR__ . '/_app_footer.php'; ?>
