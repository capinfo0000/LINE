<?php

/**
 * 会員パスワード再設定の申請。登録メールに再設定リンクを送る。
 * アカウントの有無に関わらず同じ表示にして、存在を漏らさない。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $email = trim((string) ($_POST['email'] ?? ''));

    // 濫用対策（メール爆撃防止）: 同一IPからの申請は回数制限＋CAPTCHA で抑止する。
    if (rate_limit_check('member_forgot', 5, 3600) && captcha_verify($_POST['cf-turnstile-response'] ?? null)) {
        $token = create_member_password_reset($email);
        if ($token !== null) {
            $link = base_url() . '/member/reset.php?token=' . $token;
            $body = "会員サイトのパスワード再設定のご依頼を受け付けました。\n\n"
                . "以下のリンクから1時間以内に新しいパスワードを設定してください。\n"
                . $link . "\n\n"
                . "心当たりがない場合は、このメールは破棄してください。\n";
            send_mail($email, '【Enlink】パスワード再設定のご案内', $body);
        }
    }
    $done = true; // 有無・制限に関わらず同じ応答
}

$tk = csrf_token();
$pageTitle = 'パスワード再設定';
require __DIR__ . '/_header.php';
?>
<h1>パスワード再設定</h1>
<?php if ($done): ?>
    <div class="card">
        <p>ご登録のメールアドレスにアカウントがあれば、再設定リンクを送信しました。</p>
        <p class="muted">メールが届かない場合は、アドレスをご確認のうえ再度お試しください。ご不明な場合は運営へお問い合わせください。</p>
    </div>
    <p class="muted"><a href="/member/login.php">ログインに戻る</a></p>
<?php else: ?>
    <p class="muted">ご登録のメールアドレスに再設定リンクを送ります。</p>
    <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= e($tk) ?>">
        <label>メールアドレス</label>
        <input type="email" name="email" required autocomplete="email">
        <?= captcha_widget_html() ?>
        <p style="margin-top:14px;"><button type="submit" class="btn">再設定リンクを送る</button></p>
    </form>
    <p class="muted"><a href="/member/login.php">ログインに戻る</a></p>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
