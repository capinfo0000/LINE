# デプロイ手順 — CORESERVER V2（Enlink）

Enlink（PHP + SQLite + Stripe + LINE + Zoom）を CORESERVER V2 に **SSH で git clone して公開する**手順。

構成（重要）:

```
~/enlink/               ← git clone 先（Web非公開）
├── public/             ← ★ここをドキュメントルートにする（Web公開）
├── src/ vendor/ bin/   ← Web非公開（公開ディレクトリの外）
├── data/app.sqlite     ← Web非公開（DB。uploads/=顔写真も。バックアップ対象）
└── .env                ← Web非公開（Stripe/LINE/Zoom の鍵など）
```

公開ファイルは `dirname(__DIR__).'/src/bootstrap.php'` を読むので、**構造を保ち、ドキュメントルートだけ `public/` に向ける**こと。

---

## 1. コントロールパネル（cp.coreserver.jp）

1. **PHP** 8.1 以上（拡張: `pdo_sqlite`/`curl`/`json`/`mbstring`/`gd`）。CLI は `php85cli` を使用。
2. 初期ドメイン（`xxxx.coreserver.jp` 等）を控える → `APP_BASE_URL`（https）。
3. **無料SSL（Let's Encrypt）** を有効化（Stripe/LINE とも https 必須）。
4. SSH 接続情報を確認。

## 2. コード取得

```bash
ssh ユーザー名@ホスト名
cd ~
git clone https://github.com/capinfo0000/line.git enlink
cd enlink
git checkout claude/awaiting-plan-c7ctr8   # ← 本番反映するブランチ（main 統合後は main）
```

## 3. 依存インストール

```bash
composer install --no-dev -o        # 無ければ php composer.phar install --no-dev -o
```

## 4. .env を作成

```bash
cp .env.example .env
vi .env
```

設定する主な値（詳細は `.env.example`）:

```ini
APP_BASE_URL=https://xxxx.coreserver.jp
STRIPE_SECRET_KEY=sk_live_xxx          # 最初はテスト sk_test_ で確認
STRIPE_WEBHOOK_SECRET=whsec_xxx        # 手順7で取得
JOIN_FEE_AMOUNT=2000
LINE_CHANNEL_SECRET=xxx                # LINE Developers
LINE_CHANNEL_ACCESS_TOKEN=xxx
ZOOM_ACCOUNT_ID=xxx                    # Zoom S2S（任意。無ければ手動URL）
ZOOM_CLIENT_ID=xxx
ZOOM_CLIENT_SECRET=xxx
APP_KEY=（php -r 'echo base64_encode(random_bytes(32));' で生成）
MAIL_FROM=no-reply@xxxx.coreserver.jp
MAIL_FROM_NAME=Enlink
ALLOW_SIGNUP=0
```

`.env` は `.gitignore` 済み。サーバー上にだけ置くこと。

## 5. DB 初期化 & 運営管理者作成

```bash
php85cli bin/console.php init
php85cli bin/console.php create-admin you@example.com あなたのパスワード
```

`data/app.sqlite`（WAL）と `data/uploads/`（顔写真）が作られる。

## 6. ドキュメントルートを public/ に向ける（symlink）

CORESERVER V2 は docroot が `/public_html/<ドメイン>` に固定のため、symlink で対応する。

```bash
cd ~
rm -rf public_html/xxxx.coreserver.jp
ln -s ~/enlink/public public_html/xxxx.coreserver.jp
```

## 7. Webhook 設定

- **Stripe**：ダッシュボード → 開発者 → Webhook → エンドポイント追加
  `https://xxxx.coreserver.jp/webhook.php`（イベント: `checkout.session.completed`）→ 署名シークレットを `.env` の `STRIPE_WEBHOOK_SECRET` に。
- **LINE**：Messaging API チャネルの Webhook URL を
  `https://xxxx.coreserver.jp/line_webhook.php` に設定し、Webhook 利用を ON。応答メッセージ（自動応答）は OFF 推奨。

## 8. cron 登録（`php85cli`）

| 間隔 | コマンド | 目的 |
|---|---|---|
| 10分 | `php85cli ~/enlink/bin/reconcile.php >> ~/private/reconcile.log 2>&1` | 入会金Webhook取りこぼし救済 |
| 5分 | `php85cli ~/enlink/bin/remind.php >> ~/private/remind.log 2>&1` | 予約リマインド |
| 週次 | `php85cli ~/enlink/bin/recommend.php >> ~/private/recommend.log 2>&1` | おすすめ再構築（集計） |

各スクリプトは多重起動ロック・冪等化済み。

## 9. 動作確認

- 会員入口: `https://xxxx.coreserver.jp/`
- 運営ログイン: `https://xxxx.coreserver.jp/admin/login.php`（手順5の管理者で）
- 会員ログイン: `https://xxxx.coreserver.jp/member/login.php`
- 決済テスト: 運営で予約枠・OpenChat URL を登録 →（またはテスト用 `make-member`）→ `checkout.php` でテストカード `4242 4242 4242 4242`
- 露出確認: `/.env` と `/data/app.sqlite` が 403/404 であること

## 10. 更新（2回目以降）

```bash
ssh ユーザー名@ホスト名
cd ~/enlink
git pull
composer install --no-dev -o      # 依存に変更があれば
# DBスキーマ変更は冪等（初回アクセス時に自動適用）
```

## 11. バックアップ

- 対象は `data/`（`app.sqlite` と `uploads/`）。
- SQLite はホットコピーを避け、`sqlite3 data/app.sqlite ".backup ~/private/backup.sqlite"` 等で取得を推奨。
- 顔写真 `data/uploads/` も併せて保全。

## 本番移行チェック

- [ ] `STRIPE_SECRET_KEY` を `sk_live_` に、Webhook を本番エンドポイントで登録
- [ ] `APP_BASE_URL` が本番URL（https）
- [ ] LINE Webhook 疎通（友だち追加で応答が返るか）
- [ ] Zoom 自動発行（予約→URL 生成）／失敗時は手動URL案内で運用可
- [ ] 特商法・規約・プライバシーの ［ ］ を実情報に置換
- [ ] `data/` バックアップ運用を決定
