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

## 4. 設定（.env 作成）

### 方法A：ブラウザで設定（かんたん・推奨）

手順6（docroot を public/ に向ける）まで先に済ませてから、ブラウザで
`https://xxxx.coreserver.jp/install.php` を開き、フォームに入力するだけで
**`.env` 生成・DB初期化・運営管理者作成**まで自動で行われます。完了すると
`install.php` は自動削除されます（再取得しても「設定済み」で拒否）。

- 未入力でも進められます（Stripe/LINE/Zoom は後から `.env` 編集で設定可）。
- プロジェクト直下に書き込み権限が必要です（通常の suEXEC 環境なら自分の権限で書き込み可）。
- これを使う場合、下の手順5は不要です（ウィザードが実行します）。

### 方法B：手動で作成

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

## 6-2. 運営画面のフォルダ名を変える（推奨）

`public/admin/` はURLを推測されやすいので、設置時に**推測しにくい名前へリネーム**して運用する。
（例：`public/admin_599383181b73d79e83452421de67f6/`）

```bash
mv public/admin public/admin_$(openssl rand -hex 15)
```

リネームしても壊れないように、運営画面の中は**相対リンク・相対リダイレクト**で書いてある。
人に渡すURL（招待リンク・パスワード再設定メール）は `admin_abs_url()`（`src/admin.php`）が
実フォルダ名から組み立てるので、こちらも追従する。

**注意**：更新用のZIPを作るときは、毎回このリネームを忘れないこと。
`public/admin/` のまま上げると、リネーム前のフォルダが復活して両方が公開状態になる。

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
- 運営ログイン: `https://xxxx.coreserver.jp/<リネームした運営フォルダ>/login`（手順5の管理者で）
- 会員ログイン: `https://xxxx.coreserver.jp/member/login`
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

## 12. URL の形（.php を出さない）

画面のURLは拡張子なしで出している。`.htaccess`（プロジェクト直下と `public/` の2枚）が
次の3つをやっている。**設置時にこの2枚を必ず一緒に上げること**（無いと拡張子なしのURLが404になる）。

| 入ってきたURL | 動き |
|---|---|
| `/member/directory` | 同名の `member/directory.php` があれば、それを内部で実行（URLはそのまま） |
| `/member/directory.php` | 拡張子なし `/member/directory` へ301（GETのみ） |
| `/u/<共有コード>` | `u.php` に渡す（プロフィールの共有URL） |

**次の4つは `.php` のまま受ける。**外部サービスに登録済みのURLなので、勝手に転送しない。

- `/webhook.php`（Stripe）
- `/line_webhook.php`（LINE）
- `/cron.php`（サーバーの定期実行）
- `/install.php`（初期設定。設置後は削除推奨）

以前に配ったリンクやブックマークが `.php` 付きで来ても301で拾うので、リンク切れにはならない。
POST は転送しない（301でPOSTを転送すると中身が落ちるため）。

## 13. 独自ドメインへの切り替え

初期ドメイン（`xxxx.coreserver.jp`）から独自ドメイン（例 `enlink.tokyo`）へ移すときの手順。
アプリ側にドメインの直書きは無いので、`.env` の1行と、外部サービス側の登録URLを直せば済む。

1. コントロールパネルでドメインを追加し、無料SSL（Let's Encrypt）を発行する。
   `https://<新ドメイン>/` が開くようになるまで待つ（DNS 反映に時間がかかる）。
2. `.env` の `APP_BASE_URL` を新ドメインに変える（末尾の `/` は付けない）。

   ```
   APP_BASE_URL=https://enlink.tokyo
   ```

   これで決済の戻り先・メールのリンク・**プロフィールの共有URL（`/u/<コード>`）** が
   すべて新ドメインで出るようになる。
3. 外部サービスに登録してあるURLを差し替える。

   | サービス | 直す場所 |
   |---|---|
   | Stripe | Webhook エンドポイント `https://<新ドメイン>/webhook.php` |
   | LINE Developers | Webhook URL `https://<新ドメイン>/line_webhook.php` |
   | Zoom（OAuth を使う場合） | リダイレクトURL |
   | cron（サーバーの定期実行） | 呼び出し先URLのドメイン |

4. 旧ドメインは、しばらくは生かしたまま新ドメインへ転送しておくと、
   すでに配ってしまった共有URLや決済リンクが切れない。
5. 切り替え後の確認：`cron.php?job=diag` が動くこと、会員ログイン、
   プロフィールの「共有URL」が新ドメインで出ること、テスト決済の戻り先。

## 本番移行チェック

- [ ] `STRIPE_SECRET_KEY` を `sk_live_` に、Webhook を本番エンドポイントで登録
- [ ] `APP_BASE_URL` が本番URL（https）
- [ ] LINE Webhook 疎通（友だち追加で応答が返るか）
- [ ] Zoom 自動発行（予約→URL 生成）／失敗時は手動URL案内で運用可
- [ ] 特商法・規約・プライバシーの ［ ］ を実情報に置換
- [ ] `data/` バックアップ運用を決定
- [ ] 独自ドメインに移す場合は「13. 独自ドメインへの切り替え」を実施
- [ ] `public/install.php` を削除（初期設定が済んだら不要。認証なしで開ける画面を残さない）
- [ ] 運営画面のフォルダをリネーム（「6-2」）。更新ZIPを作るたびに同じ名前にそろえる
