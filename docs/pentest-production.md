# 本番ペネトレーションテスト結果（読み取りのみ・非破壊）

- 対象: https://event.coresv.com
- 実施日: 2026-06-13
- 範囲: オーナー許可のもと、**APIキー（Stripe 秘密鍵）が Web 経由で取得可能か**を黒box調査
- 手法: 認証情報の総当たり・DoS・変更/削除は行わず、**GET による露出確認のみ**
- 前提: 本資料時点で本番は**ハードニング前の旧コード**が稼働（ブランチ `claude/loving-hawking-m22ci7` は未デプロイ）

## 結論

**今回試した経路では API キーは取得できなかった。** 直接ファイル取得・ソース開示・VCS 露出・ディレクトリ一覧・管理画面の未認証アクセスはいずれも遮断されていた。

## 試行と結果

| 探索 | 結果 | 評価 |
|---|---|---|
| `/.env` | 404 | 露出なし |
| `/stripe_secret.key` | 404 | 露出なし |
| `/app/stripe_secret.key`, `/app/`, `/app/.env` | 403 | 存在するが拒否（保護） |
| `/data/`, `/data/app.sqlite`, `/app.sqlite` | 404 | 露出なし |
| `/logs/`, `/logs/mail.log`, `/logs/payments.log` | 403 / 404 | 保護/なし |
| `/src/bootstrap.php`, `*.bak`, `*~`, `*.txt` | 404 | ソース開示なし |
| `/.git/config`, `/.git/HEAD`, `/.svn/entries` | 404 | VCS 露出なし |
| `/composer.json`, `/composer.lock` | 404 | なし |
| 管理系 `/admin/dashboard.php`, `/admin/stripe.php`, `/admin/setup.php`, `/admin/account.php`, `/admin/index.php` | 302 → `/admin/login.php` | 認証ガード有効 |

## 発見事項

### F-1（低）情報露出: テンプレート/メタファイルが公開配信
- `/.env.example`（HTTP 200）, `/.gitignore`（HTTP 200）が閲覧可能。
- 秘密そのものではないが、設定変数名・ファイル構成（後述）が読める。
- 対策: Web から `.gitignore` / `*.md` / `*.example` 等を配信しない（今回の `.htaccess` 対策に拒否ルールあり）。

### F-2（要対応・構成差異）本番コードがリポジトリと別系統の可能性
- 本番 `/.gitignore` に、リポジトリ `capinfo0000/event` に**存在しない**記述:
  - `stripe_secret.key`, `/app/stripe_secret.key`（=「画面から設定する Stripe 秘密鍵の保存ファイル」）
- 本番に `/admin/stripe.php`・`/admin/setup.php` が存在（リポジトリのブランチには無く、こちらは `connect.php`/`account.php`）。
- → 本番は「管理画面で鍵を設定 → `stripe_secret.key` に保存」する**旧/別系統**。ハードニング版をデプロイする際に**差分の擦り合わせが必須**。

### サーバ情報
- `Server: Apache`、`Strict-Transport-Security: max-age=31536000` 応答あり。

## 未検証（要追加許可）の残経路
- **Stripe 設定画面の鍵露出**: `/admin/stripe.php`（302＝存在）に、保存済み `sk_live_...` が `value=` 等で**ログイン済み運営者に表示**されていないか。表示されていれば「ログインした運営者なら誰でもソースから鍵取得」可能な典型漏洩。検証にはテストアカウントでのログイン（読み取りのみ）が必要。

## 推奨対応（優先度順）
1. ハードニング版デプロイ時、**docroot を `public/` に固定**し、`.env`/鍵ファイル/`logs`/`data`/`src` を Web 非公開に（`.htaccess` 同梱済み）。
2. `/.env.example`・`/.gitignore` 等のメタファイルを公開配信しない。
3. 鍵をファイル保存する旧設計なら、保存先を**docroot外**に置き、設定画面で**鍵を画面に再表示しない**（マスク `****` のみ）。
4. 本番コードとリポジトリの**コードベース統一**（差分解消）。
