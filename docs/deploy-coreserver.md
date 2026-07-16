# デプロイ手順 — CORESERVER V2（SSH + 無料ドメイン）

このアプリ（PHP + SQLite + Stripe）を CORESERVER V2（cp.coreserver.jp）に
**SSH で git clone して公開する**手順。無料ドメイン（初期ドメイン）を使う前提。

前提となる構成（重要）:

```
~/event/                ← git clone 先（Web非公開）
├── public/             ← ★ここをドキュメントルートにする（Web公開）
├── src/  vendor/  bin/ ← Web非公開（公開ディレクトリの外）
├── data/app.sqlite     ← Web非公開（DB。バックアップ対象）
└── .env                ← Web非公開（Stripe鍵などの機密）
```

公開ファイルは `dirname(__DIR__).'/src/bootstrap.php'` を読むので、
**プロジェクトの構造をそのまま保ち、ドキュメントルートだけ `public/` に向ける**こと。

---

## 0. 事前準備（ローカル / GitHub）

最新コードは作業ブランチ側にあるため、**main に統合してから**サーバーで main を clone する。
（あるいは clone 時に作業ブランチを指定してもよい。）

```bash
# ローカルで（例）
git checkout main
git merge claude/clever-carson-JRdli
git push origin main
```

---

## 1. コントロールパネル設定（cp.coreserver.jp）

1. **PHP バージョン**: 8.1 以上に設定（サイト設定 → PHP設定）。
   - 必須拡張: `pdo_sqlite` / `curl` / `json` / `mbstring`（標準で有効なはず）。
2. **無料ドメイン（初期ドメイン）の確認**: `xxxx.coreserver.jp` などを控える。
   → これが `APP_BASE_URL` になる（https）。
3. **無料SSL（Let's Encrypt）を有効化**: サイト設定 → 無料SSL。
   - 反映に数分〜十数分かかる。Stripe決済は https 必須。
4. **SSH接続情報を確認**: SSH設定でホスト名・ユーザー名・ポート(22)、
   公開鍵登録 or パスワードを確認。

---

## 2. SSH ログイン & コード取得

```bash
ssh ユーザー名@ホスト名      # パネルで確認した接続情報

# ホームに clone（既存の public_html とは別に置く）
cd ~
git clone https://github.com/capinfo0000/event.git
cd event
git checkout main           # または最新の作業ブランチ
```

---

## 3. 依存インストール（composer）

```bash
# どちらか動く方を使う
composer install --no-dev -o
# composer が無ければ同梱の phar を使用
php composer.phar install --no-dev -o
```

> `php -v` が 8.1+ を指していることを確認。CLIとWebでPHP版が違う場合あり。

---

## 4. .env を作成

```bash
cp .env.example .env
nano .env        # または vi
```

設定する値:

```ini
# 自分の Stripe シークレットキー（最初はテスト sk_test_… で確認）
STRIPE_SECRET_KEY=sk_test_xxxxxxxx

# 公開URL（無料ドメイン + https）。末尾スラッシュ不要
APP_BASE_URL=https://xxxx.coreserver.jp

# 送信メール差出人（自分のドメインのアドレス推奨）
MAIL_FROM=no-reply@xxxx.coreserver.jp
MAIL_FROM_NAME=イベント事前決済

# Webhook を使うなら（任意・後述）
# STRIPE_WEBHOOK_SECRET=whsec_xxxx
```

> `.env` は `.gitignore` 済み。サーバー上にだけ置き、GitHubには絶対に上げない。

---

## 5. DB 初期化 & 管理者作成

```bash
php bin/console.php init
php bin/console.php create-admin you@example.com あなたのパスワード
```

- `data/app.sqlite` が作られる（SQLite。WALモード）。
- suEXEC 環境なのでファイルは自分の権限＝PHPから書き込み可能。

---

## 6. ドキュメントルートを public/ に向ける（シンボリックリンク）

【重要】CORESERVER V2 の「サイト設定の変更」フォームには**ドキュメントルートの入力欄が無い**。
ドキュメントルートは `/public_html/<ドメイン>` に**固定**で、パネルからは変更できない。
したがって、固定のドキュメントルートをアプリの `public/` へ向ける**シンボリックリンク**で対応する。

```bash
cd ~
ls -la public_html/event.coresv.com         # 中身確認（初期は空ディレクトリのはず）
rm -rf public_html/event.coresv.com         # 空ディレクトリを削除
ln -s ~/event/public public_html/event.coresv.com
ls -la public_html/                          # event.coresv.com -> /virtual/<user>/event/public を確認
```

- ホームは `/virtual/<アカウント名>/`（例 `/virtual/gquxalve/`）。
- リンク先とリンク元の所有者が同一（自分）なので Apache の SymLinksIfOwnerMatch を満たし、追加設定なしで表示される。

---

## 7. 動作確認

ブラウザで以下を開く:

- 参加者トップ: `https://xxxx.coreserver.jp/`
- 運営ログイン: `https://xxxx.coreserver.jp/admin/login.php`
  → 手順5で作った管理者でログイン → イベント登録 → 申込リンク発行 → テスト決済。

Stripe はテストモードのキーなら、テストカード `4242 4242 4242 4242`
（有効期限=未来の任意日、CVC=任意3桁）で決済を試せる。

---

## 8. Stripe Webhook（任意）

現状の決済フローは Webhook 未設定でも動作する。プラン課金（アップグレード）連携や
取りこぼし防止で使うなら:

1. Stripe ダッシュボード → 開発者 → Webhook → エンドポイント追加
2. URL: `https://xxxx.coreserver.jp/webhook.php`
3. 署名シークレット（`whsec_…`）を `.env` の `STRIPE_WEBHOOK_SECRET` に設定。

---

## 9. 更新（2回目以降）

```bash
ssh ユーザー名@ホスト名
cd ~/event
git pull
composer install --no-dev -o      # 依存に変更があれば
# DBスキーマ変更があれば init は冪等（既存テーブルは壊さない設計）
```

---

## トラブルシューティング

| 症状 | 原因 / 対処 |
|---|---|
| 500 エラー・白画面 | `~/event` 直下に `vendor/` があるか、`.env` があるか確認。PHP版8.1+か。 |
| `設定エラー: 環境変数 ... が未設定` | `.env` の該当キーを設定（特に `STRIPE_SECRET_KEY`）。 |
| SQLite書き込みエラー | `data/` の所有者が自分か確認。WALが不安定なら `src/db.php` の `journal_mode = WAL` を `DELETE` に。 |
| src/.env がURLで見える | ドキュメントルートが `public/` を指していない。手順6を見直す。 |
| メールが届かない | `MAIL_FROM` をサーバーのドメインのアドレスにする（なりすまし扱い回避）。 |
| 決済画面に行けない | `APP_BASE_URL` が https の正しいドメインか、SSLが有効か確認。 |

---

## 本番移行チェック

- [ ] `STRIPE_SECRET_KEY` を `sk_live_…`（本番キー）に差し替え
- [ ] `APP_BASE_URL` が本番URL（https）
- [ ] `data/`（SQLite）のバックアップ運用を決める
- [ ] 特商法・利用規約・プライバシーの各ページ内容を確定（`public/tokushoho.php` ほか）
- [ ] テスト決済を本番キーで1件通して着金を確認
