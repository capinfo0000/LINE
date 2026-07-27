<?php

/**
 * パスワード再設定の申請。メールに再設定リンクを送る。
 * アカウントの有無に関わらず同じ表示にして、存在を漏らさない。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $email = trim((string) ($_POST['email'] ?? ''));

    // 濫用対策（メール爆撃防止）: 同一IPからの再設定申請は回数制限＋CAPTCHA で抑止する。
    // ブロック時もアカウント有無を漏らさないよう、表示は常に同じにする。
    if (rate_limit_check('forgot', 5, 3600) && captcha_verify($_POST['cf-turnstile-response'] ?? null)) {
        $token = create_password_reset($email);
        if ($token !== null) {
            $link = base_url() . '/admin/reset.php?token=' . $token;
            $body = "パスワード再設定のご依頼を受け付けました。\n\n"
                . "以下のリンクから1時間以内に新しいパスワードを設定してください。\n"
                . $link . "\n\n"
                . "心当たりがない場合は、このメールは破棄してください。\n";
            send_mail($email, 'パスワード再設定のご案内', $body);
        }
    }
    $done = true; // 有無・制限に関わらず同じ応答
}

$tk = csrf_token();
require __DIR__ . '/_auth_header.php';
?>
<h1>パスワード再設定</h1>
<?php if ($done): ?>
    <div class="card">
        <p>ご入力のメールアドレスにアカウントがあれば、再設定リンクを送信しました。</p>
        <p class="muted">メールが届かない場合は、アドレスをご確認のうえ再度お試しください。</p>
    </div>
    <p class="muted"><a href="login.php">ログインに戻る</a></p>
<?php else: ?>
    <p class="muted">登録メールアドレスに再設定リンクを送ります。</p>
    <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= e($tk) ?>">
        <label>メールアドレス</label>
        <input type="email" name="email" required autocomplete="email">
        <?= captcha_widget_html() ?>
        <p style="margin-top:14px;"><button type="submit" class="btn">再設定リンクを送る</button></p>
    </form>
    <p class="muted"><a href="login.php">ログインに戻る</a></p>
<?php endif; ?>
<?php require __DIR__ . '/_auth_footer.php'; ?>
