<?php
/**
 * 「事前決済について」モーダル（参加者向け・Stripe の安全性説明）。
 * 公開ページ（apply.php / o.php 等）で require して使う。
 * 開閉は /assets/app.js（data-modal-open="prepayInfo" / data-modal-close）。
 */
declare(strict_types=1);
?>
<div class="modal" id="prepayInfo" role="dialog" aria-modal="true">
    <div class="modal__box">
        <button type="button" class="modal__close" data-modal-close aria-label="閉じる">×</button>
        <div class="modal__title">事前決済について</div>
        <p class="modal__lead">このイベントのカード決済は、世界的な決済代行サービス <strong>Stripe（ストライプ）</strong> を通じて行われます。以下は、その安全性を客観的な事実にもとづいて説明したものです。</p>

        <div class="guide__row"><div class="guide__num">1</div><div class="guide__body">
            <div class="gt">カード情報は主催者にも当サイトにも渡りません</div>
            <p>カード番号・有効期限・セキュリティコード（CVC）の入力は、Stripe がホストする決済ページ上で直接行われます。主催者のサーバーや当サイトのデータベースを一切通過せず、保存もされません。そのため、万一このサイトのデータが漏れても、カード情報は含まれません。</p>
        </div></div>
        <div class="guide__row"><div class="guide__num">2</div><div class="guide__body">
            <div class="gt">国際的なセキュリティ基準「PCI DSS 準拠レベル1」</div>
            <p>Stripe は、クレジットカード業界の国際セキュリティ基準 PCI DSS の最上位（Service Provider Level 1）に認定されています。これは年間数百万件以上を扱う決済事業者に求められる、最も厳格なレベルです。</p>
        </div></div>
        <div class="guide__row"><div class="guide__num">3</div><div class="guide__body">
            <div class="gt">通信の暗号化とトークン化</div>
            <p>すべての通信は TLS（HTTPS）で暗号化されます。さらにカード情報は「トークン化」され、実際のカード番号を直接やり取りしない仕組みになっています。</p>
        </div></div>
        <div class="guide__row"><div class="guide__num">4</div><div class="guide__body">
            <div class="gt">不正利用対策（Radar・3Dセキュア）</div>
            <p>機械学習による不正検知 Stripe Radar や、本人認証の 3Dセキュア（EMV 3-D Secure）に対応し、なりすまし・不正利用のリスクを低減します。</p>
        </div></div>
        <div class="guide__row"><div class="guide__num">5</div><div class="guide__body">
            <div class="gt">世界的な実績と規制対応</div>
            <p>Stripe は世界中の多数の企業に採用されている大手決済基盤で、各国の金融規制に準拠して運営されています（日本でも資金決済・割賦販売に関する法令に対応）。</p>
        </div></div>
        <div class="guide__row"><div class="guide__num">6</div><div class="guide__body">
            <div class="gt">返金・トラブル時の保護</div>
            <p>支払い後の返金は正規のフローで処理され、記録が残ります。身に覚えのない請求にはカード会社のチャージバック（異議申立）制度も利用できます。</p>
        </div></div>

        <p class="hint" style="margin-top:14px;">出典・詳細は Stripe 公式の情報をご確認ください：
            <a href="https://stripe.com/jp/privacy" target="_blank" rel="noopener">プライバシー</a> ／
            <a href="https://stripe.com/docs/security" target="_blank" rel="noopener">セキュリティ</a>。<br>
            ※ 本説明は決済代行（Stripe）の仕組みに関する一般的な解説です。</p>
        <div class="modal__actions">
            <button type="button" class="btn" data-modal-close>閉じる</button>
        </div>
    </div>
</div>
