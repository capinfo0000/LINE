<?php

/**
 * 会員ログイン（発行ログインID＋パスワード）。
 * 初回ログイン時は must_change_pw によりPW変更ページへ誘導される。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $loginId = trim((string) ($_POST['login_id'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // 総当たり対策：発行ID単位（標的型）と IP 単位（ID横断スプレー）の両方で失敗回数を制限。
    if (recent_failed_logins($loginId) >= 5 || recent_failed_logins_by_ip(client_ip()) >= 20) {
        audit_log('member.login.blocked', ['login_id' => $loginId]);
        $error = '試行回数が多すぎます。しばらく時間をおいてからお試しください。';
    } elseif (!captcha_verify($_POST['cf-turnstile-response'] ?? null, true)) {
        $error = '認証（CAPTCHA）に失敗しました。もう一度お試しください。';
    } elseif (login_member($loginId, $password)) {
        clear_failed_logins($loginId);
        audit_log('member.login.ok', ['login_id' => $loginId]);
        // 初回PW強制変更が必要なら変更ページへ、そうでなければダッシュボードへ。
        $m = current_member();
        if ($m !== null && (int) ($m['must_change_pw'] ?? 0) === 1) {
            header('Location: /member/change_password');
        } else {
            // 共有URLから来た場合はその画面へ戻す。無ければ会員トップ。
            $back = take_login_return_path();
            header('Location: ' . ($back !== '' ? $back : '/member/dashboard'));
        }
        exit;
    } else {
        record_failed_login($loginId);
        audit_log('member.login.fail', ['login_id' => $loginId]);
        $error = 'ログインIDまたはパスワードが違います。';
    }
}

// すでにログイン済みなら会員トップ（共有URLから来ていればその画面）へ
if (current_member() !== null) {
    $back = take_login_return_path();
    header('Location: ' . ($back !== '' ? $back : '/member/dashboard'));
    exit;
}

$token = csrf_token();
// 公式LINEのトークURL：管理画面「各種設定」優先、無ければ .env をフォールバック。
// ID・仮パスワードはこのトークで配っているので、分からなくなった人の戻り先になる。
$officialUrl = site_setting('line_official_url');
if ($officialUrl === '') {
    $officialUrl = (string) (env('LINE_OFFICIAL_URL', '') ?? '');
}
$pageTitle = '会員ログイン';
require __DIR__ . '/_header.php';
?>
<h1>会員ログイン</h1>
<p class="muted" style="margin:0 0 14px;">
    Enlink（縁リンク）は、「提供できること」と「求めていること」が噛み合う相手だけに出会える会員制のビジネスマッチングです。
    <a href="/about">サービスについて →</a>
</p>
<?php if ($error !== ''): ?>
    <div class="flash flash--ng">
        <?= e($error) ?>
        <div style="font-size:.82rem;margin-top:6px;font-weight:400;">
            分からない場合は <a href="/member/forgot">パスワードを忘れた場合</a> から再設定できます。
        </div>
    </div>
<?php endif; ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
    <label>ログインID</label>
    <input type="text" name="login_id" required autocomplete="username" autocapitalize="none" spellcheck="false">
    <label>パスワード</label>
    <input type="password" name="password" required autocomplete="current-password">
    <?= captcha_widget_html() ?>
    <p style="margin-top:16px;"><button type="submit" class="btn">ログイン</button></p>
</form>
<p class="muted"><a href="/member/forgot">パスワードを忘れた場合</a></p>
<p class="muted" style="margin-bottom:8px;"><?= e(ops_credentials_description()) ?></p>
<?php if ($officialUrl !== ''): ?>
    <p style="margin:0 0 4px;">
        <a class="btn btn--line" href="<?= e($officialUrl) ?>" target="_blank" rel="noopener">公式LINEのトークを開く →</a>
    </p>
<?php endif; ?>
<p class="muted" style="margin-top:20px;border-top:1px solid var(--border);padding-top:14px;font-size:.82rem;">
    <a href="/about">サービスについて</a> ／
    <a href="/tokushoho">特定商取引法に基づく表記</a> ／
    <a href="/policy">キャンセル・返金ポリシー</a> ／
    <a href="/terms">利用規約</a> ／
    <a href="/privacy">プライバシーポリシー</a>
</p>
<?php require __DIR__ . '/_footer.php'; ?>
