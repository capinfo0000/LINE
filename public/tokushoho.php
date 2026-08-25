<?php

/**
 * 特定商取引法に基づく表記。
 * 事業者情報・価格の文面は管理画面「各種設定」から編集できる（app_settings の site_* キー）。
 * ※ 有料サービス・決済を伴うため、日本では原則として表示が必要です。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$title = '特定商取引法に基づく表記';
require __DIR__ . '/_legal_header.php';

$bizName = site_setting('biz_name');
$bizOwner = site_setting('biz_owner');
$bizAddr = site_setting('biz_address');
$bizMail = site_setting('biz_email');
$bizTel = site_setting('biz_tel');
$contact = trim($bizMail . ($bizMail !== '' && $bizTel !== '' ? '／' : '') . $bizTel);
$unset = '<span class="muted">（未設定）</span>';
?>
<table>
    <tr><th>販売事業者</th><td><?= $bizName !== '' ? e($bizName) : $unset ?></td></tr>
    <tr><th>運営責任者</th><td><?= $bizOwner !== '' ? e($bizOwner) : $unset ?></td></tr>
    <tr><th>所在地</th><td><?= $bizAddr !== '' ? e($bizAddr) : '請求があれば遅滞なく開示します' ?></td></tr>
    <tr><th>連絡先</th><td><?= $contact !== '' ? e($contact) : $unset ?></td></tr>
    <tr><th>販売価格</th><td><?= nl2br(e(site_setting('price_note'))) ?></td></tr>
    <tr><th>商品代金以外の必要料金</th><td>インターネット接続料金等はお客様のご負担となります。</td></tr>
    <tr><th>支払方法</th><td>クレジットカード（決済代行：Stripe）による月額自動課金（サブスクリプション）</td></tr>
    <tr><th>支払時期</th><td>ご登録時に初回分をお支払いいただき、以降は毎月自動的に課金されます。</td></tr>
    <tr><th>提供時期</th><td>ご登録・入金確認後、会員サイトをただちにご利用いただけます。</td></tr>
    <tr><th>解約方法</th><td>会員サイトの「お支払い・解約の管理」からいつでも解約できます。解約すると次回以降の請求が停止します（当月分の日割り返金はありません）。</td></tr>
    <tr><th>返品・キャンセル</th><td>デジタルサービスの性質上、お支払い済みの月額会費の返金は原則お受けできません。詳細は<a href="policy.php">キャンセル・返金ポリシー</a>をご確認ください。</td></tr>
</table>
<?php if ($bizName === '' || $bizOwner === '' || $contact === ''): ?>
<p class="muted">※ 事業者情報が未設定です。管理画面の「各種設定」から入力してください。</p>
<?php endif; ?>
<?php require __DIR__ . '/_legal_footer.php'; ?>
