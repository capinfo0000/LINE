<?php

/**
 * 料金と紹介特典の詳しい説明（公開ページ）。
 *
 * 会費の条件と紹介特典の条件は、口頭やチャットで説明するには枝分かれが多い。
 * 「5人紹介したら無料」だけを覚えて入会した方が、あとで「5人を割ったら戻る」
 * 「無料になった人も人数に残る」を知らずに請求されると必ず揉める。
 * 判定の実物と同じ数字・同じ言葉で1ページにまとめ、会員画面から必ずここへ来られるようにする。
 *
 * 金額・人数・しきい値はすべて設定から取る（monthly_fee_text() / billing_free_limit() /
 * referral_waiver_min() / referral_waiver_mode()）。設定を変えたときに文面だけ
 * 古いまま残らないようにする。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$title = '料金と紹介特典について';
$fee = monthly_fee_text();
$limit = billing_free_limit();
$min = referral_waiver_min();
$permanent = $min * $min;
$reached = billing_reached_at() !== null;
$grace = billing_grace_active();
$startsAt = billing_starts_at();
$modeB = referral_waiver_mode() === 'B';

$metaDescription = 'Enlink（縁リンク）の月額会費と紹介特典の条件を詳しく説明します。'
    . 'ご紹介した' . $min . '名が会費を契約されると月額会費は無料。'
    . 'その' . $min . '名がそれぞれ' . $min . '名ずつご紹介されると、以後ずっと無料になります。';

/**
 * よくある質問。ここは JSON-LD（FAQPage）にもそのまま渡すので、
 * 画面に出している文章と1文字も変えないこと（違う内容だと検索側で無効になる）。
 *
 * @var array<int, array{q:string, a:string}>
 */
$faq = [
    [
        'q' => '月額会費はいくらですか？',
        'a' => '月額会費は' . $fee . 'です。会員数が' . $limit . '名を超えた翌月1日から始まります。'
            . 'それまでにご入会された方も、超えた月のうちは無料のままです。',
    ],
    [
        'q' => '紹介すると月額会費が無料になるのはどういう条件ですか？',
        'a' => 'ご紹介された方が' . $min . '名、月額会費をご契約されている状態になると、'
            . 'ご紹介者の月額会費は無料になります。' . $min . '名「以上」であれば無料が続きます。',
    ],
    [
        'q' => '紹介した人が解約したらどうなりますか？',
        'a' => 'ご契約中の方が' . $min . '名を下回ると、翌月から通常の月額会費に戻ります。'
            . '別の方を新たにご紹介いただき、その方がご契約されて' . $min . '名に戻れば、再び無料になります。'
            . '解約された方ご本人が再契約される必要はありません。',
    ],
    [
        'q' => '紹介した人が紹介特典で無料になったら、私の人数から外れますか？',
        'a' => '外れません。その方も月額会費をご契約されている状態が続いているため、人数に含まれ続けます。'
            . '人数から外れるのは、完全に解約された方だけです。',
    ],
    [
        'q' => '無料が取り消されない「永久無料」はありますか？',
        'a' => 'あります。ご紹介いただいた' . $min . '名が、それぞれ' . $min . '名ずつご紹介され、'
            . 'その方々もご契約されている状態になると（ご紹介者から見て' . $permanent . '名）、以後は人数が減っても無料のままになります。',
    ],
    [
        'q' => '会費を払わないとどうなりますか？',
        'a' => '退会にはなりません。会員のまま、プロフィールの編集・公開や、'
            . 'ご自身へのおすすめの閲覧はこれまでどおりご利用いただけます。'
            . '会員を検索する「さがす」だけがご利用いただけなくなります。',
    ],
    [
        'q' => 'いつでも解約できますか？',
        'a' => 'いつでも解約できます。会員サイトの「お支払い・解約の管理」から、'
            . 'Stripe の管理ページでお手続きいただけます。解約されると次回以降の請求が停止します。',
    ],
];

$extraJsonLd = service_jsonld() . '    ' . faq_jsonld($faq);
require __DIR__ . '/_legal_header.php';
?>
<p class="lead">Enlink の月額会費と、ご紹介による割引の条件をまとめています。<strong>判定に使っている条件そのもの</strong>を記載しています。</p>

<h2>1. 月額会費</h2>
<table>
    <tr><th>金額</th><td><strong><?= e($fee) ?></strong>（税込）</td></tr>
    <tr><th>始まる時期</th><td>会員数が <strong><?= (int) $limit ?>名</strong>を超えた、<strong>その翌月1日</strong>から</td></tr>
    <tr><th>お支払い</th><td>クレジットカード（Stripe）。カード情報は Stripe が扱い、当方では保持しません</td></tr>
    <tr><th>解約</th><td>いつでも可能。次回以降の請求が停止します</td></tr>
</table>

<?php if ($grace && $startsAt !== null): ?>
    <p class="flash flash--ng" style="margin:14px 0;">現在は<strong>猶予期間</strong>です。<?= e(date('n月j日', $startsAt)) ?>から月額会費が始まります。
        いまお申し込みいただいても、<strong>最初のご請求は<?= e(date('n月j日', $startsAt)) ?>から</strong>です。それまでの分は頂きません。</p>
<?php elseif (!$reached): ?>
    <p class="flash flash--ok" style="margin:14px 0;">現在は<strong>無料</strong>でご利用いただけます。会員数が<?= (int) $limit ?>名を超えるまでは会費は発生しません。</p>
<?php endif; ?>

<h2>2. 会費を払わない場合</h2>
<p>退会にはなりません。会員のままで、使えなくなるのは<strong>「さがす」（会員の検索）だけ</strong>です。</p>
<table>
    <tr><th>使えるもの</th><td>プロフィールの編集・公開、共有URL、ご自身へのおすすめ、ポイント・紹介、意見箱</td></tr>
    <tr><th>使えないもの</th><td>「さがす」（会員を検索して一覧から探す機能）</td></tr>
</table>

<h2>3. 紹介特典（月額会費が無料になります）</h2>
<p>ご紹介には<strong>2つの段階</strong>があります。</p>

<h3 style="font-size:.98rem;margin-top:18px;">第1段階：月額会費が無料になる</h3>
<p>ご紹介された方が<strong><?= (int) $min ?>名以上</strong>、月額会費をご契約されている状態になると、
    <strong>あなたの月額会費は無料</strong>になります。</p>
<p class="muted" style="font-size:.9rem;">※この無料は<strong>取り消されることがあります</strong>。下の「4. 人数が減ったとき」をご覧ください。</p>

<h3 style="font-size:.98rem;margin-top:18px;">第2段階：以後ずっと無料になる（永久無料）</h3>
<p>ご紹介いただいた<strong><?= (int) $min ?>名</strong>が、それぞれ<strong><?= (int) $min ?>名以上</strong>をご紹介され、
    その方々もご契約されている状態になると（あなたから見て<strong><?= (int) $permanent ?>名</strong>）、
    <strong>以後は人数が減っても無料のまま</strong>になります。</p>

<h2>4. 人数が減ったとき</h2>
<p>第1段階の無料は、ご契約中のご紹介先が<strong><?= (int) $min ?>名を下回ると、翌月から通常の月額会費に戻ります</strong>。
    当月分はすでに0円で確定しているため、通常額になるのは次回のご請求からです。</p>
<p>戻すには、<strong>別の方を新たにご紹介いただき、その方がご契約されれば</strong>再び無料になります。
    解約された方ご本人が再契約される必要はありません。</p>

<h2>5. 人数の数え方</h2>
<table>
    <tr><th>数えるもの</th><td>ご紹介された方のうち、<strong>月額会費をご契約中</strong>の方</td></tr>
    <tr><th>数えないもの</th><td>紹介コードを入力しただけで、まだご契約されていない方</td></tr>
    <tr><th>解約された方</th><td><strong>人数から外れます</strong></td></tr>
    <tr><th>紹介特典で無料になった方</th><td><?php if ($modeB): ?><strong>人数から外れます</strong>（現在は実際にお支払い中の方のみを数える設定です）<?php else: ?><strong>人数に含まれ続けます</strong>。ご契約自体は続いているためです<?php endif; ?></td></tr>
</table>
<p class="muted" style="font-size:.9rem;">紹介コードのご登録は<strong>お一人1回だけ</strong>で、あとから変更・取り消しはできません。ご自身のコードはご登録いただけません。</p>

<h2>6. 具体例</h2>
<p>Aさんが5名（Bさん〜Fさん）をご紹介し、5名ともご契約された場合を例にします（<?= (int) $min ?>名の設定のとき）。</p>
<table>
    <tr><th style="width:46%;">できごと</th><th>Aさんの月額会費</th></tr>
    <tr><td>B〜Fの<?= (int) $min ?>名がご契約</td><td><strong>無料</strong></td></tr>
    <tr><td>Dさんが解約（<?= (int) $min - 1 ?>名に）</td><td>翌月から<strong>通常額</strong></td></tr>
    <tr><td>Aさんが新たにGさんをご紹介し、Gさんがご契約（<?= (int) $min ?>名に）</td><td>再び<strong>無料</strong></td></tr>
    <tr><td>Cさんが紹介特典で無料になった（ご契約は継続）</td><td><?php if ($modeB): ?>人数から外れるため<strong>通常額</strong><?php else: ?>人数は<?= (int) $min ?>名のまま<strong>無料</strong><?php endif; ?></td></tr>
    <tr><td>B〜Fがそれぞれ<?= (int) $min ?>名ずつご紹介（計<?= (int) $permanent ?>名）</td><td><strong>以後ずっと無料</strong></td></tr>
    <tr><td>そのあとB〜Fが全員解約</td><td>永久無料のため<strong>そのまま無料</strong></td></tr>
</table>

<h2>7. ご注意</h2>
<ul>
    <li>ご紹介先の方が<strong>実際に月額会費をご契約されるまで</strong>、人数には入りません。</li>
    <li>同一の方が複数のアカウントを作ってご紹介数を増やす行為は、規約違反として無料を取り消す場合があります。</li>
    <li>紹介特典の判定は自動で定期的に行われます。ご契約の直後は反映まで時間差が生じることがあります。</li>
    <li>ここに記載の人数・金額は変更される場合があります。変更する際は事前にご案内します。</li>
</ul>

<h2>よくあるご質問</h2>
<dl class="feat">
    <?php foreach ($faq as $qa): ?>
        <dt><?= e($qa['q']) ?></dt>
        <dd><?= e($qa['a']) ?></dd>
    <?php endforeach; ?>
</dl>

<p style="margin-top:24px;"><a href="/about">サービスについて</a>　<a href="/terms">利用規約</a>　<a href="/policy">キャンセル・返金ポリシー</a></p>
<?php require __DIR__ . '/_legal_footer.php'; ?>
