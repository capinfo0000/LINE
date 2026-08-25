# 外部サービスの鍵の取り方（Stripe / LINE / Zoom）

`install.php` や `.env` に入力する値の取得手順。**すべて後から設定でもOK**（まず空欄で立ち上げ→ログインだけでも動く）。

---

## Stripe（入会金の決済）

入力する値は2つ：`STRIPE_SECRET_KEY` と `STRIPE_WEBHOOK_SECRET`。

1. https://stripe.com で無料登録（事業者情報・入金先の銀行口座・本人確認。審査に数日かかる場合あり）。
2. **シークレットキー**：ダッシュボード →「開発者」→「APIキー」→ **シークレットキー**
   - テスト用 `sk_test_…`（まずこれで動作確認）／本番用 `sk_live_…`
   - 「表示」を押してコピー
3. **Webhook 署名シークレット**：ダッシュボード →「開発者」→「Webhook」→「エンドポイントを追加」
   - URL：`https://あなたのドメイン/webhook.php`
   - 送信イベント：`checkout.session.completed`
   - 作成後の画面に表示される **署名シークレット `whsec_…`** をコピー

> 順番のコツ：Webhookはサイト公開後に登録するので、最初は**シークレットキーだけ**入れて立ち上げ→公開後にWebhookを追加でOK。
> テストカード：`4242 4242 4242 4242`（有効期限は未来の任意日、CVCは任意3桁）。

## LINE（公式アカウント／自動案内Bot）

入力する値は2つ：`LINE_CHANNEL_SECRET` と `LINE_CHANNEL_ACCESS_TOKEN`。

1. https://developers.line.biz/ にLINEアカウントでログイン。
2. プロバイダーを作成 → **「Messaging API」チャネル**を新規作成（公式アカウントも同時に用意される）。
3. **チャネルシークレット**：チャネルの「チャネル基本設定」→ **Channel secret**
4. **チャネルアクセストークン**：「Messaging API設定」→ **チャネルアクセストークン（長期）**→「発行」
5. 同じ「Messaging API設定」で：
   - **Webhook URL** に `https://あなたのドメイン/line_webhook.php` を設定
   - 「**Webhookの利用**」を ON
   - 「**応答メッセージ（自動応答）**」は OFF 推奨（Botの会話と競合しないように）

## Zoom（説明会URLの自動発行・任意）

入力する値は3つ：`ZOOM_ACCOUNT_ID` / `ZOOM_CLIENT_ID` / `ZOOM_CLIENT_SECRET`。
**未設定でもOK**（その場合は予約は取れて、URLだけ手動案内になります）。

1. https://marketplace.zoom.us/ にログイン →「Develop」→「Build App」。
2. **「Server-to-Server OAuth」** アプリを作成。
3. アプリの「App Credentials」画面に **Account ID / Client ID / Client Secret** が表示される。
4. 「Scopes」に**会議作成の権限（meeting:write 系）**を追加。
5. アプリを「Activate（有効化）」。

> 有料Zoom（Pro等）＋管理者権限が前提。無料アカウントは会議40分制限。使わないなら3つとも空欄で。

---

## 何を入れれば何が動く？

| やりたいこと | 必要な入力 |
|---|---|
| 管理者ログインだけ・まず動かす | なし（全部空欄） |
| 入会金を取る | Stripe 2つ（Webhookは公開後でOK） |
| LINEで自動オンボーディング | LINE 2つ |
| 説明会Zoom自動発行 | Zoom 3つ（任意） |

## 後から変更したいとき

`install.php` は完了後に消えるので、後からは **`.env` を直接編集**します（該当行を書き換えて保存）。
ファイル編集が不安な場合は「運営コンソールから鍵を設定する画面」を追加することも可能です。
