# START HERE — Enlink 引き継ぎ・指示書

このZIP（またはリポジトリ）だけで、別の環境／別セッションで作業を続けられるようにまとめた資料です。
**まずこのファイルを読めばOK**。詳細は各リンク先へ。

---

## 0. これは何？

**Enlink** … 会員制の人脈マッチングサービス（PHP + SQLite + Stripe + LINE + Zoom）。

- 公式LINE Bot でオンボーディング自動化 → **入会金¥2,000（買い切り・一回払い）** 決済 → 会員サイト（発行ID/PWログイン）
- 会員はプロフィール（タグ・自由記述・リンク・顔写真・求める条件）を登録
- **条件検索**（一方向）＋**双方向マッチのおすすめ**（お互いの希望が合致した相手のみ）
- 運営コンソール（会員管理・写真承認・予約枠・一斉配信・OpenChat URL・タグ・統計）

技術背景：既存アプリ `event`（ペネトレ済み）を基盤に、単一運営向けへ作り替え。

## 1. 現在地（実装状況）

**Phase 0〜8 すべて実装・ローカル検証済み**。名称は Enlink。ブラウザ初期セットアップ（`install.php`）付き。

| Phase | 内容 |
|---|---|
| 0 | 基盤フォーク＆単一運営化 |
| 1 | 会員認証（発行ID/PW・初回PW強制変更） |
| 2 | 入会金決済（Stripe Checkout＋Webhook＋照合cron・冪等） |
| 3 | LINE Botオンボーディング＋予約＋Zoom＋ID/PW配布 |
| 4 | プロフィール編集（タグ/自由記述/リンク/顔写真/求める条件） |
| 5 | ディレクトリ・条件検索 |
| 6 | 双方向マッチおすすめ |
| 7 | 運営コンソール |
| 8 | 法務・ハードニング・本番化 |

未完了（＝コードでなく「情報の記入・外部接続」が必要なもの）は §6 参照。

## 2. ローカルで動かす（最短）

前提：**PHP 8.1以上**（拡張 `pdo_sqlite` / `curl` / `json` / `mbstring` / `gd`）。このZIPは `vendor/` 同梱なので composer 不要。

```bash
# 展開したフォルダ内で
php -S localhost:8000 -t public
```

ブラウザで **http://localhost:8000/install.php** を開き、フォームに入力（Stripe/LINE/Zoomは空欄でOK）。
→ `.env` 生成・DB初期化・運営管理者作成が完了し、`install.php` は自動削除されます。

- 運営：http://localhost:8000/admin/login.php
- 会員：http://localhost:8000/member/login.php

### install.php を使わず手動で動かす場合
```bash
cp .env.example .env      # 最低 APP_BASE_URL=http://localhost:8000 だけでも可
php bin/console.php init
php bin/console.php create-admin you@example.com あなたのパスワード8文字以上
php -S localhost:8000 -t public
```

### 動作を一通り試す（テストデータ）
```bash
php bin/console.php make-member test@example.com "テスト会員"   # 会員を手動発行（ID/仮PW表示）
php bin/console.php create-slot seminar "2026-09-01 19:00" 5    # 説明会枠
php bin/console.php add-openchat "https://line.me/ti/g2/xxxx"   # OpenChat URL
php bin/console.php list-members
```

## 3. 事前準備チェックリスト

**まず必須（会員サイト立ち上げ＆ログインまで）**
- [ ] CoreServer V2 契約（PHP 8.1+ / SSH / `gd`拡張）
- [ ] ドメイン＋**無料SSL（https）**
- [ ] 運営ログイン用メール＆パスワード

**入会金を取るなら（Stripe）** … 取得手順は `docs/setup-credentials.md`
- [ ] Stripeアカウント（事業者情報・入金先口座・本人確認／審査に数日かかる場合あり）
- [ ] シークレットキー / Webhook署名シークレット

**LINE自動オンボーディングするなら**
- [ ] LINE公式アカウント＋Messaging APIチャネル（シークレット／アクセストークン）
- [ ] オープンチャットを1部屋作成 → 招待URL

**Zoom自動発行するなら（任意）**
- [ ] Zoom（Pro推奨）＋Server-to-Server OAuthアプリ（Account/Client ID・Secret）

**決めて書き出しておく（法的に必要）**
- [ ] 特商法：事業者名／運営責任者／所在地／連絡先
- [ ] 説明会・面談の日程

## 4. 外部サービスの鍵の取り方

→ **`docs/setup-credentials.md`** に Stripe / LINE / Zoom の取得手順を詳しく記載。

## 5. 本番デプロイ（CoreServer）

→ **`docs/deploy-coreserver.md`**。要点：
1. `git clone` or ZIP展開 → `composer install --no-dev -o`（ZIPならvendor同梱で省略可）
2. ブラウザで `/install.php`（かんたん）／または手動 `.env`
3. docroot を **`public/` に symlink**
4. Webhook：Stripe=`/webhook.php`、LINE=`/line_webhook.php`
5. cron：`bin/reconcile.php`(10分) / `bin/remind.php`(5分) / `bin/recommend.php`(週次)
6. 確認：`/.env` と `/data/app.sqlite` が 403/404

## 6. 残タスク（本番前）

- [ ] 法務ページの ［ ］ を実情報に（`public/tokushoho.php` `terms.php` `privacy.php`）
- [ ] Stripe本番キー化＋Webhook本番登録（先にテストキーで1件通す）
- [ ] LINE Webhook 実疎通（友だち追加で応答が返るか）
- [ ] Zoom自動発行の確認（失敗時は手動URLで運用可）
- [ ] `data/` バックアップ運用（`app.sqlite` と `uploads/`）

## 7. ファイルの地図

```
public/           Web公開（docrootはここ）
  install.php     初期セットアップウィザード（完了後に自己削除）
  index.php       入口／会員・運営への導線
  member/         会員エリア（login/change_password/profile/directory/recommend/member_view/photo）
  admin/          運営コンソール（members/member_detail/photos/slots/broadcast/openchat/tags/dashboard…）
  checkout.php webhook.php line_webhook.php success.php cancel.php
  tokushoho.php terms.php privacy.php policy.php   法務ページ
src/              非公開ロジック
  bootstrap.php   共通基盤（env/CSP/CSRF/監査ログ/Stripe初期化）
  db.php          SQLiteスキーマ（冪等マイグレーション＋タグ初期投入）
  tenant.php      運営者認証   member.php 会員認証   profile.php プロフィール
  directory.php   検索         match.php   双方向おすすめ
  payment.php     入会金プロビジョニング   line.php Bot   booking.php 予約   zoom.php Zoom
  admin.php       運営操作     mail.php/captcha.php/crypto.php
bin/              CLI/cron（console.php / reconcile.php / remind.php / recommend.php）
docs/             deploy-coreserver.md / setup-credentials.md ほか
data/             実行時生成（DB・顔写真・ログ）※ZIPには含めない
.env              実行時生成（秘密）※ZIPには含めない
PLAN.md           確定仕様書   README.md 概要
```

## 8. 別のAIセッションで続きを頼むときの指示文（コピペ用）

> このZIPは PHP+SQLite の会員制マッチングサービス「Enlink」です。`START-HERE.md` と `PLAN.md` を読んで現状を把握してください。Phase 0〜8は実装済み・ローカル検証済みです。
> まず `php -S localhost:8000 -t public` で起動し `/install.php` で初期設定できることを確認してください。
> 次にお願いしたいのは【ここに依頼内容：例「法務ページの事業者情報を入れる」「運営画面からLINE/Stripeの鍵を設定する画面を追加」「デザイン調整」など】です。
> 変更したら `php -l` で構文確認し、ローカルの組み込みサーバで実際に動かして検証してから知らせてください。

## 9. リポジトリ

GitHub：`capinfo0000/line` の `claude/awaiting-plan-c7ctr8` ブランチに全コミット済み。ZIPと同一内容です。
