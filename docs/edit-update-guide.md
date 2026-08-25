# 編集・更新手順（コードを直して本番に反映する）

このアプリは **GitHub経由でデプロイ**している。
編集は「**ローカルで直す → GitHubへpush → サーバーでpull**」の流れで行う。

> サーバー上のファイルを直接編集しないこと。次の `git pull` で上書き・消失し、
> バージョン管理もされないため。必ずローカル → GitHub → サーバーの順で反映する。

---

## 環境の前提

| 場所 | パス / URL | 役割 |
|---|---|---|
| ローカル（Windows） | `C:\work\claude\event` | 編集する作業コピー |
| GitHub | `https://github.com/capinfo0000/event`（main ブランチ） | コードの正本 |
| 本番サーバー | CORESERVER `~/event`（= `/virtual/gquxalve/event`） | 公開中の実体 |
| 公開URL | https://event.coresv.com/ | 参加者・主催者がアクセス |

- SSH: `ssh gquxalve@m11.coreserver.jp`（パスワードはコンパネ表示。**接続元IP許可は30日で失効**）
- サーバーのPHP CLIは必ず **`php85cli`** を使う（素の `php` は7.4でアプリが動かない）

---

## 基本の3ステップ

### ① ローカルで編集
`C:\work\claude\event` のファイルを編集する。
（Claude に「○○を直して」と頼めばこの場で編集してもらえる。）

### ② コミットして GitHub へ push
```powershell
cd C:\work\claude\event
git add -A
git commit -m "変更内容のメモ"
git push origin main
```
> `git push origin main` は自分の手で実行する（Claude が出すと安全弁で止まるため）。

### ③ サーバーに反映（SSH）
```bash
ssh gquxalve@m11.coreserver.jp
cd ~/event
git pull
# composer.json（依存ライブラリ）を変えたときだけ追加で:
php85cli composer.phar install --no-dev -o
```
→ `https://event.coresv.com/` をブラウザで再読み込みすれば反映完了（PHPはビルド不要）。

---

## おすすめの役割分担

1. **あなた** → Claude に「△△を直して」と依頼
2. **Claude** → ローカルを編集し `git commit` まで実行
3. **あなた** → `git push origin main` を実行
4. **あなた** → サーバーで `cd ~/event && git pull`（依存変更時のみ composer install）

---

## ローカルでの事前確認について

ローカルPCのPHPは **7.4** で、このアプリは **8.1+** が必要なため、
`php -S localhost:8000 -t public` での確認は**そのままではエラー**になる。

- **手軽**: ローカル確認は省略し、push→pull後に **本番URLで確認**
  （小規模運用なら実用上これで十分。申込リンクを広く配る前に自分で一通り触る）
- **きちんと**: ローカルに **PHP 8.x** を入れる、または確認用の別サブドメインを用意する

---

## よくあるトラブル

| 症状 | 原因 / 対処 |
|---|---|
| `git pull` で「Your local changes would be overwritten」 | サーバー上で直接編集してしまった。`git checkout -- <file>` で破棄してから pull。今後はローカル編集に統一 |
| SSHが急に繋がらない（Connection reset） | 接続元IP許可の30日失効。コンパネ「ツール/セキュリティー」→「SSH接続IP許可」を再登録 |
| 反映されない | ブラウザのキャッシュ。スーパーリロード（Ctrl+F5）。または `git pull` がサーバーで実行できているか確認 |
| `php` を実行したら 7.4 で動かない | サーバーでは `php85cli` を使う（例 `php85cli bin/console.php ...`） |
| 500エラー | `~/event` に `vendor/` と `.env` があるか確認。`.env` の必須値（`STRIPE_SECRET_KEY` 等）の設定漏れ |

---

## 関連ドキュメント
- `docs/deploy-coreserver.md` — 初回デプロイの全手順
- `README.md` — アプリの構成・セットアップ
