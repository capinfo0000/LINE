# 別PCで再開する手順 ＆ バックアップ

「GitHubから取ってくれば続きができる」——コードは正しい。
ただし **GitHubに入っていないもの** があるので、それを別途用意・保護する必要がある。

---

## 1. コードとデータの違い（重要）

| 種類 | 正本の置き場所 | GitHubにある? |
|---|---|---|
| **コード**（src/ public/ bin/ など） | GitHub | ✅ ある |
| `.env`（Stripe鍵などの**秘密情報**） | 各環境にだけ | ❌ ない（.gitignoreで除外） |
| `data/app.sqlite`（**アカウント・イベントの実データ**） | 動かしている環境にだけ | ❌ ない（.gitignoreで除外） |
| `vendor/`（ライブラリ） | composerで復元 | ❌ ない（composerで再生成） |

→ **コードはGitHubが正本。秘密情報と実データは各環境にだけ存在する。**

---

## 2. 別PCで「開発の続き」をする手順

前提: その PC に **PHP 8.1+** と **Composer**（または composer.phar）が入っていること。

```bash
git clone https://github.com/capinfo0000/event.git
cd event
composer install                 # vendor/ を復元
cp .env.example .env             # .env を作り、値を入れ直す（下記参照）
php bin/console.php init         # 開発用に空のDBを作成
php bin/console.php create-admin you@example.com パスワード
php -S localhost:8000 -t public  # ローカル起動（要 PHP 8.1+）
```

> 開発用のDBは **空から作る**。本番の実データは持ってこない（持ってくる場合は §4 を参照）。

---

## 3. .env に必要な項目（GitHubには無いので毎回用意する）

正本は `.env.example`。最低限これらを設定する：

| キー | 内容 | 必須度 |
|---|---|---|
| `STRIPE_SECRET_KEY` | 自分のStripeシークレットキー（`sk_test_…`／本番`sk_live_…`） | 決済を使うなら必須 |
| `APP_BASE_URL` | 公開URL（本番は `https://event.coresv.com`、ローカルは `http://localhost:8000`） | 必須 |
| `MAIL_FROM` / `MAIL_FROM_NAME` | 送信メールの差出人 | メール機能を使うなら |
| `STRIPE_WEBHOOK_SECRET` | Webhook署名シークレット（`whsec_…`） | Webhookを使うなら |
| `STRIPE_PRICE_P5` / `_P10` / `_UNLIMITED` | 有料プランのStripe Price ID | プラン課金を使うなら |

> 💡 **本番の `.env` の控えを安全な場所に1つ持っておく**と、環境を作り直すときに楽
> （ただしGitHubやチャット等、第三者が見られる場所には置かない）。

---

## 4. 本番データ（data/app.sqlite）のバックアップ

本番の「アカウント・イベント」データは **CORESERVER の `~/event/data/` だけ** に存在する。
GitHubには無いので、**別途バックアップが必須**。

### 方法A: ローカルにダウンロード（SCP）
自分のPCのターミナルから：
```bash
scp -r gquxalve@m11.coreserver.jp:~/event/data ./backup-$(日付)
```
> SQLiteはWALモードのため `app.sqlite` 単体ではなく **`data/` フォルダごと**
> （`app.sqlite` / `-wal` / `-shm`）を取得すると確実。

### 方法B: サーバー上で整合性のあるスナップショットを作る
SSHで（書き込み中の取りこぼしを防ぐ）：
```bash
cd ~/event
php85cli -r "(new PDO('sqlite:data/app.sqlite'))->exec('VACUUM INTO \"data/backup-snapshot.sqlite\"');"
# できた data/backup-snapshot.sqlite を SCP/SFTP で取得
```

### 方法C: コンパネの「無料バックアップ」
コントロールパネル → サイト設定 → **無料バックアップ**（サーバー全体の自動バックアップ）。
手軽だが、特定ファイルのピンポイント復元には方法A/Bの自前バックアップが安心。

### 復元
バックアップした `app.sqlite` を `~/event/data/app.sqlite` に戻すだけ。

---

## 5. まとめ（チェックリスト）

別環境を作る／PCを変えるとき：
- [ ] `git clone` でコード取得
- [ ] `composer install` で vendor 復元（PHP 8.1+ 必須）
- [ ] `.env` を作成し値を設定（`.env.example` 参照。秘密情報の控えから）
- [ ] 開発なら `php bin/console.php init` で空DB。本番データを使うならバックアップから復元
- [ ] 本番データは定期的にバックアップ（§4）

## 関連
- `docs/edit-update-guide.md` — 日常の編集→反映フロー
- `docs/deploy-coreserver.md` — 初回デプロイの全手順
- `.env.example` — 環境変数の正本
