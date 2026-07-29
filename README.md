# Enlink — 会員制 人脈マッチングサービス

会員制の人脈マッチングサービスです。公式LINE（Bot）でオンボーディングを自動化し、
入会金（買い切り・一回払い）の決済後に会員専用サイト（発行ID/PWログイン）で
人脈ディレクトリ・条件検索・双方向マッチのおすすめを提供します。

既存アプリ `event`（PHP+SQLite+Stripe・ペネトレ済み）を基盤として継承し、単一運営向けに作り替えたものです。
実装方針・確定仕様は [`PLAN.md`](PLAN.md) を参照。

## 技術スタック

- PHP 8.1+（本番 CLI/cron は `php85cli`）／独自軽量FW（`src/bootstrap.php`）
- SQLite（PDO・WAL）／Stripe Checkout（同梱 SDK）
- サーバレンダリング PHP（LIFF/SPA なし・CSP 厳格）

## 主な機能

| 領域 | 内容 |
|---|---|
| オンボーディング | 公式LINE Bot：友だち追加→説明会/面談予約（Zoom自動発行）→（承認）→決済リンク→入金でID/PW＋OpenChat配布 |
| 入会金決済 | Stripe Checkout（mode=payment・¥2,000買い切り）＋Webhook＋照合cron（取りこぼし救済） |
| 会員サイト | 発行ID/PWログイン（初回PW強制変更）、プロフィール編集（タグ/自由記述/リンク/顔写真/求める条件） |
| 検索・おすすめ | 条件検索（一方向）＋双方向マッチのおすすめ（お互いの希望が合致した相手のみ） |
| 運営コンソール | 会員管理・予約枠・一斉配信（通数見積り）・OpenChat URL・タグ管理・統計 |

## ディレクトリ構成

```
public/            ← ドキュメントルート（ここだけ Web 公開）
  index.php        会員/運営の入口
  member/          会員エリア（login/change_password/profile/directory/recommend/photo…）
  admin/           運営コンソール
  checkout.php webhook.php line_webhook.php success.php cancel.php  法務ページ
src/               ← 非公開：bootstrap/db/tenant/member/profile/directory/match/payment/booking/zoom/line/admin/mail/captcha/crypto
bin/               ← CLI/cron：console.php reconcile.php remind.php recommend.php
data/              ← 非公開：app.sqlite・uploads/（顔写真）・ログ（バックアップ対象）
.env               ← 非公開：Stripe/LINE/Zoom の鍵など
```

## セットアップ（ローカル開発）

前提: PHP 8.1+（`pdo_sqlite`/`curl`/`json`/`mbstring`/`gd`）, Composer。

```bash
composer install --no-dev -o
cp .env.example .env    # 各種キーを設定（下記）
php bin/console.php init
php bin/console.php create-admin you@example.com あなたのパスワード
php -S localhost:8000 -t public
# 運営: http://localhost:8000/admin/login.php
# 会員: http://localhost:8000/member/login.php（IDは入金 or `make-member` で発行）
```

### 主な .env（詳細は `.env.example`）

- `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` … 入会金決済（本番は `sk_live_`）
- `JOIN_FEE_AMOUNT` … 入会金（既定 2000）
- `LINE_CHANNEL_SECRET` / `LINE_CHANNEL_ACCESS_TOKEN` … 公式LINE Bot（Webhook: `/line_webhook.php`）
- `ZOOM_ACCOUNT_ID` / `ZOOM_CLIENT_ID` / `ZOOM_CLIENT_SECRET` … Zoom S2S（未設定なら手動URLにフォールバック）
- `APP_BASE_URL` … 公開URL（success/cancel/webhook 生成に使用）
- `ALLOW_SIGNUP` … 運営者のオープン登録可否（単一運営は 0＝招待制）

## 運用CLI

```bash
php bin/console.php create-admin <email> <pw>   # 運営管理者を作成
php bin/console.php make-invite <admin-email>   # 運営者招待コード
php bin/console.php make-member [email] [name]  # 会員を手動発行（テスト用）
php bin/console.php create-slot seminar "2026-08-01 19:00" 5   # 予約枠
php bin/console.php add-openchat <url>          # OpenChat 招待URL
php bin/console.php approve-contact <line_user_id>  # 承認して決済リンク送信
```

## cron（`php85cli`）

| スクリプト | 目的 | 目安間隔 |
|---|---|---|
| `bin/reconcile.php` | 入会金Webhook取りこぼしの救済（Stripe照合） | 10分 |
| `bin/remind.php` | 予約リマインド（LINE Push） | 5分 |
| `bin/recommend.php` | 双方向おすすめの再構築（集計用） | 週次 |

各スクリプトは多重起動ロック・冪等化済み。ログは `data/`（非公開）へ。

## デプロイ・バックアップ

- CoreServer V2：`git clone` → docroot を `public/` に symlink、`.env`/`data`/`src`/`vendor` は公開領域外。
- 更新は `git pull`（DBマイグレーションは冪等）。詳細は `docs/deploy-coreserver.md`。
- バックアップ対象は `data/`（`app.sqlite` と `uploads/`）。定期取得を推奨。

## セキュリティ

- カード情報は Stripe 上でのみ入力・処理し、当サーバーは保持しません（PAN非保持）。
- CSP(nonce)/HSTS/X-Frame-Options、CSRF、レート制限、bcrypt、監査ログ。
- 顔写真は公開領域外に保存し、認可付き配信＋運営モデレーション（承認制）。
- 機密（`.env`/DB/`src`）は docroot 外配置＋`.htaccess` deny。
