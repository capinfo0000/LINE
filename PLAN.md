# Enlink — 確定実装プラン（既存「event」基盤を継承）

> 本ドキュメントは合意済みの確定仕様。実装はこのプランに従う。
> ベース＝ユーザー提供の既存アプリ `event`（PHP+SQLite+Stripe・ペネトレ済み）を**基盤として継承**し、
> ドメイン層を「イベント」から「会員／人脈ディレクトリ／マッチング＋LINE Bot＋予約」へ作り替える。

## 1. Context（何を作るか）

会員制の人脈マッチングサービス。従来の「note に全会員のプロフィール＋LINE追加URLを手動掲載」という
静的名簿を、**公式LINE（Bot）でオンボーディング自動化 ＋ 会員専用Webサイト（発行ID/PWログイン）で
人脈ディレクトリ＋双方向マッチング**に置き換える。会員は自分の情報（LINE追加リンク含む）と
「求める条件」を入力し、条件が双方合致した相手がおすすめ表示され、条件検索もできる。
入会金（買い切り・1回払い・月額なし）で課金。

デプロイ先は CoreServer（GMO V2）。既存アプリと同じ配置方式（git clone → docroot を `public/` に
シンボリックリンク、`.env`/`data`/`src`/`vendor` は公開領域外、CLIは `php85cli`）を踏襲。

## 2. 確定した要件（Decisions）

| 項目 | 決定 |
|---|---|
| ベース | 既存 `event` アプリの基盤を流用。ドメイン層は新規。**マルチテナントは撤去して単一運営化** |
| 技術スタック | PHP 8.1+（本番 `php85cli`）／独自軽量FW（`src/bootstrap.php`）／SQLite（PDO・WAL）／Stripe（同梱）。画面は**サーバレンダリングPHP**。LIFF/SPA/MySQLは使わない |
| リポジトリ | `capinfo0000/line`（本リポジトリ）にこのまま構築。開発ブランチ `claude/awaiting-plan-c7ctr8` |
| **入会金** | **¥2,000 の買い切り・一回払い（月額なし）**。入金完了で会員権は永続 |
| 会員ログイン | **発行ID＋パスワード**（入金完了時に発行）。**初回ログインでPW強制変更**。LINEログイン/LIFFは使わない |
| **ID/PW配布** | 入金後、**公式LINE Botで発行ID＋仮PWを送付**（初回PW強制変更で緩和）。メールは面談〜決済で取得し、PW再発行（forgot/reset）に使用 |
| 認証（運営側） | 既存の管理コンソール認証（email/PW・招待制）を流用 |
| 連絡・自動送信 | **公式LINE（Messaging API Bot）中心**。予約案内・面談誘導・決済リンク・ID/PW配布・OpenChat URL配信 |
| 加入承認 | **決済前の任意ゲート**。運営が承認した相手にだけ Bot が決済リンクを送る（承認なし運用も可） |
| 予約／Zoom | Zoom説明会・個別面談の枠予約・確定・リマインドを内製。**Zoom API（Server-to-Server OAuth）で会議URL自動発行**。失敗時は**枠だけ確定＋手動URL案内**にフォールバック |
| 決済の作り方 | 既存 Stripe 層を転用：**Checkout（mode=payment 一回払い）＋ webhook.php（署名検証・冪等）＋照合cron**。カード情報・鍵は自前で扱わない（鍵はAES-256-GCM暗号保存） |
| 交流の受け皿 | **LINEオープンチャット**。招待URLはAPI生成不可 → 運営が1本作成、Botが入金後に自動配信。Bot は部屋内に入れないため部屋内自動投稿はしない |
| マッチング | **条件ベースの双方向ルールマッチング**（下記 §5）。MLなし。おすすめは会員サイト内表示（通数課金なし） |
| LINE追加リンク | **会員サイト内（ディレクトリ／おすすめ）に各会員のLINE追加URLを掲載**。既定＝有効会員に常時相互表示。会員は個別に非表示化可。「気になる→相互で開示」方式は今回スコープ外 |

## 3. オンボーディング動線

```
[公式LINE Bot] 友だち追加 → Zoom説明会予約（Zoom API自動発行）
  ↓ 説明会 → 個別面談案内・予約 → 面談 →（任意）加入承認1クリック
  ↓ 決済リンク（Stripe Checkout ¥2,000 一回払い）自動送信
  ↓ 入金Webhook → 会員化(active) ＋ ID/仮PW発行(must_change_pw=1) ＋ OpenChat URL を Botで配布
  ↓
[会員] 発行ID/PWでログイン → 初回PW強制変更 → プロフィール入力
  → ディレクトリ条件検索／双方向マッチのおすすめ表示 → 相手のLINE追加URLから直接つながる
```

## 4. プロフィール項目（会員が入力）

**タグ（選択式・検索とおすすめの軸／運営がマスタを追加可能）**
- 場所（都道府県から選択）
- 仕事ジャンル（例：IT・製造・建設・飲食・小売・医療・士業・金融・不動産・教育・クリエイティブ・その他）
- 目的＝求めること（例：協業・顧客獲得・仕入/調達・採用・資金調達/投資・情報交換・仲間づくり・メンター探し）
- **提供できること**（目的と同一マスタから選択。双方向マッチの精度向上に使用）

**自由記述**
- 名前
- 年齢
- 会社名／屋号・肩書き
- ひとことPR（一覧カードの見出し用・短文）
- 自己紹介

**リンク（複数貼れる）**
- LINE追加URL（必須寄り）＋任意リンク（SNS・Webサイト等）

**顔写真／アイコン**（画像アップロード：原本のまま送信→サーバでEXIF補正・正方形384px・シャープ化・WebP化・目標30KB）

**求める条件（選択式・未指定＝問わない）**
- 相手の場所
- 相手の仕事ジャンル
- 相手の目的（→相手の「提供できること」と照合）

**表示制御**
- `visibility_flags`：LINE追加URLや各項目の個別非表示、ディレクトリ掲載オン/オフ

## 5. 検索とおすすめ（双方向マッチ）

- **条件検索**（一方向・探索用）：場所・仕事ジャンル・目的タグ ＋ キーワードで自由に絞り込み。
  条件に合致すれば双方向でなくてもヒットする。
- **おすすめ（双方向のみ）**：
  - A に B をおすすめするのは **「A の求める条件に B が合致」かつ「B の求める条件に A が合致」** の
    両方が成立するときだけ。片方向だけの一致はおすすめに出さない（検索には出る）。
  - 軸ごとに「**指定があればAND、未指定はワイルドカード**」。
  - スコア = 「求めること↔提供できること」の重なりを最重視＋他軸（場所・仕事・目的）の一致で加点。
  - 上位から表示（既定 上位20件程度／0件時は「該当なし」）。
  - `recommendations` に保存。**週次cron更新＋ログイン時オンデマンド再計算**。

## 6. データモデル（SQLite・単一運営・既存パターンで追加）

`members` のログインID（発行ID）＋`password_hash` が会員認証。既存の認証・レート制限テーブルを流用。

- **members**（id, login_id UNIQUE(発行ID), password_hash, must_change_pw, display_name, line_user_id NULL, email, status[lead/pending_payment/active/suspended/cancelled], approval_state, stripe_customer_id, joined_at, created_at）
- **profiles**（member_id PK/FK, name_text, age_text, company_title, headline, bio, photo_path, offer_note, visibility_flags JSON）
- **member_links**（member_id FK, kind[line_add/other], label, url, sort_order）
- **tag_categories**（key[area/job/purpose/offer], label）／**tags**（category_key, label, sort）／**member_tags**（member_id, tag_id）※area/job/purpose/offer を格納
- **match_preferences**（member_id FK, seek_area JSON, seek_job JSON, seek_purpose JSON）※未指定＝問わない
- **recommendations**（batch_id, member_id, recommended_member_id, score, reason_json, UNIQUE(member_id, recommended_member_id, batch_id)）
- **payments**（member_id, stripe_checkout_session_id, stripe_payment_intent_id, amount, currency, status, paid_at）＋**stripe_events**（event_id UNIQUE, processed_at）※冪等
- **groups**（name, kind='openchat', invite_url(手動登録), is_active）＋**member_group_entitlements**
- **line_contacts**（line_user_id UNIQUE, member_id NULL, onboarding_state[added/booked_seminar/seminar_done/booked_interview/interview_done/approved/payment_sent/paid], created_at）※Botファネル状態
- **bookings**（id, kind[seminar/interview], line_user_id/member_ref, slot_id FK, status[booked/done/cancelled/noshow], zoom_url, created_at）
- **slots**（id, kind, start_at, capacity, remaining, zoom_meeting_id NULL, is_open）
- **line_messages**（line_user_id, direction, type, payload, cost_estimate, sent_at）※通数コスト記録
- 流用：`login_attempts`／`rate_events`／`password_resets`
- **撤去**：`tenants`（マルチテナント）／`invites`はプラットフォーム管理者招待用に縮退流用／`events`／`headcount_cache`

## 7. デプロイ配置

```
~/enlink/            ← git clone 先（Web非公開）
├── public/              ← ★docroot をここへ symlink
│   ├── index.php        （会員サイト入口・ID/PWログイン）
│   ├── member/          （会員エリア：プロフィール編集・ディレクトリ・検索・おすすめ）新規
│   ├── admin/           （運営コンソール：既存流用＋会員/予約/タグ/配信管理を追加）
│   ├── webhook.php      （Stripe Webhook：署名検証・冪等）転用
│   ├── line_webhook.php （LINE Messaging API Webhook：Botオンボーディング）新規
│   ├── checkout.php / success.php / cancel.php（入会金決済：転用）
│   └── tokushoho/privacy/terms/policy.php（法務：流用）
├── src/  vendor/  bin/  ← Web非公開（bootstrap/db/crypto/mail/captcha/auth ＋ 新規 line.php/booking.php/zoom.php/match.php/member.php）
├── data/app.sqlite      ← Web非公開（SQLite。バックアップ対象）＋ uploads/（顔写真・非公開配信）
└── .env                 ← Web非公開（Stripe鍵・Messaging APIトークン/署名・Zoom S2S OAuth・APP_KEY・JOIN_FEE_AMOUNT=2000）
```

- CLI/cron は `php85cli`。cron：予約リマインド／週次おすすめ／決済照合（Webhook取りこぼし救済）。多重起動ロック。
- CSPは会員サイトでも厳格維持（外部SDK読込なし）。顔写真は公開領域外に保存し、認可付き配信スクリプト経由で表示。

## 8. フェーズ構成（build order・リスク順）

0. **基盤フォーク＆棚卸し** — event取り込み→イベント/マルチテナント除去。bootstrap/db/crypto/mail/captcha/認証/Stripe/法務/deployは残す。単一運営化。
   完了条件：本番URLで管理ログイン・法務表示、`/.env`・`/data/app.sqlite` が 403/404。
1. **会員認証（ID/PW）＋会員エリア土台** — メール/PW認証を会員転用、`members`、初回PW強制変更、forgot/reset、空ダッシュボード。
2. **入会金¥2,000決済ゲート＋ID/PW発行**（最重要） — Checkout一回払い→webhook（生body署名検証・`stripe_events`冪等）→active化＋ID/仮PW発行→照合cron救済。
3. **LINE Bot オンボーディング＋予約＋配布** — `line_webhook.php`、`slots`/`bookings`＋リマインドcron、Zoom S2S自動発行（フォールバックあり）、承認1クリック、入金後ID/PW＋OpenChat配布。
4. **プロフィール自己編集** — タグ（場所/仕事/目的/提供）＋自由記述＋リンク複数＋顔写真＋求める条件＋visibility。
5. **ディレクトリ 閲覧/条件検索** — 有効会員限定。タグ＋キーワード。LINE追加URLをサイト内表示（既定相互表示・個別非表示可）。
6. **双方向マッチおすすめ** — §5のルール。`recommendations`、週次cron＋ログイン時再計算。
7. **運営コンソール拡張** — 会員管理（一覧/検索/承認/入金/ID再発行）・予約枠・OpenChat URL・タグマスタ・一斉配信（通数見積り）・統計。
8. **法務・ハードニング・本番化** — 法務ページ確定＋加入同意、ログ/バックアップ、非公開再確認、本番Stripeキー切替。

## 9. 主要リスクと対策

- **Stripe Webhook（最高severity＝「払ったのに開かない」）**：署名検証流用。会員化＋ID/PW発行は**Webhook駆動＋照合cron**を安全網に、`stripe_events`で冪等。Bot配布も冪等ガード（二重発行防止）。
- **ID/PWをLINEで配る**：Botのトーク履歴に仮PWが残る → **初回PW強制変更**＋有効期限。将来「リンクでPW設定」方式に拡張可。
- **LINE通数**：Reply（ユーザー操作への応答）は無料。課金は Push のみ（リマインド・面談案内・決済リンク・入金後ID/PW＋OpenChat）で1人あたり約4通。会員サイト操作は通数ゼロ。ID/PW＋OpenChatは1Pushにまとめる。
- **Zoom**：Server-to-Server OAuth（社内利用）前提。無料は40分制限→Pro推奨。トークンはキャッシュ＆失効時再取得。会議作成失敗時は枠確定＋手動URL。
- **顔写真**：公開領域外保存＋認可付き配信。アップロードは巨大な原本でも可（上限は `public/.user.ini` で引き上げ）。サーバ側で【EXIF向き補正→中央正方形クロップ→384px→シャープ化→WebP化→目標30KB以下まで品質を下げて圧縮】し、会員あたり最小化した1ファイルのみ保存（原本は保存しない）。画像形式/サイズ(<=10MB)検証あり。承認フローは廃止し即公開。
- **単一運営化**：マルチテナント権限判定を剥がす際に権限境界を壊さないようテスト担保。
- **法務（個人情報保護法）**：会員PII＋人脈グラフは要配慮。同意取得・各種規定。カードはStripe任せでPAN非保持。

## 10. 検証方法（Verification）

- **Phase 0**：本番URLで管理ログイン・法務表示、`/.env`・`/data/app.sqlite` が 403/404。
- **Phase 1–2（背骨）**：発行ID/PWで会員ログイン可／テストカード¥2,000入金→会員化＋ID/PW発行／Webhook停止でも照合cronで救済。
- **Phase 3**：LINE操作のみで「追加→説明会予約→面談案内→決済→ID/PW＆OpenChat案内」が通る。リマインドがcronで届く。
- **Phase 4–6**：会員2アカウントで プロフィール入力→条件検索ヒット→**双方向条件が合致した相手だけおすすめ表示**。LINE追加URL表示可否が設定で切替。
- **Phase 7**：管理画面から会員承認・ID再発行・予約枠管理・OpenChat URL登録・タグ管理・一斉配信（配信前に推定通数表示）。
- **通数**：会員サイト操作でLINE通数が増えないことを配信履歴で確認。
- 各フェーズ完了時に CoreServer 実機へ `git pull` 反映して確認。

---

## 11. 料金システム Ver.1（今後の方針・2026-08 受領）

**フェーズ課金モデル**
- **〜100名：完全無料**（全機能利用可）。この間は決済ゲートなしで会員登録できる。
- **101人目の登録で課金開始**：既存を含む**全会員が月額500円（一律）**。
  - ※カード未登録の既存会員に自動課金は不可。実運用は「全員へサブスク登録を促し、未登録はアクセス制限」。
- **紹介による月額無料特典**：
  - 全会員に紹介コード（＝会員ID）を発行。新規登録時に入力で紹介者を紐付け。
  - **アクティブ有料会員（サブスク有効な紹介先）を5名紹介で月額無料**。
  - アクティブ人数が5名未満に戻ると月額500円に復帰（動的）。→ cronで再計算＋Stripe 100%クーポンの適用/解除。
- **500名突破後：広告・配信オプション**（月額500円とは別料金）
  - イベント告知配信 / 求人掲載 / 商品・サービス広告配信 / キャンペーン告知。

**目的**：初期は会員数最優先→100名で月額収益確保→紹介で口コミ促進→500名以降に広告事業を追加。

**実装メモ（現状との差分）**
- 現在は「入会金/サブスクを決済して会員化」。Ver.1は「100名まで無料登録」なので**入会フローの分岐（無料期間/課金期間）**が必要。
- 現在の紹介はポイント付与（+100/+50/毎月+50pt）。Ver.1の「5名で無料」は**別軸の特典**（ポイントは併存可）。
- 「アクティブ有料会員数」= referrals×members.subscription_status='active' で集計（cron）。
- しきい値(100/500)判定・状態遷移・全員一斉の課金開始通知が必要。

### 11.1 実装状況（2026-08 実装）

**課金フェーズ判定（#18）**
- `billing_started()`：有効会員が `BILLING_FREE_LIMIT`（既定100）を超えた時点で課金フェーズ開始。一度始まると `app_settings.billing_started=1` で戻さない。
- `member_requires_subscription($member)`：課金フェーズ中で `subscription_status!='active'` の会員はアクセス制限（`require_member` が `/member/subscribe.php` へ誘導）。無料フェーズは全員素通り。

**無料フェーズの入会経路：公式LINE経由で申込→承認発行（#18）**
- 運営が `admin/contacts.php`（LINE申込者）で申込者を確認し「承認」する。
- `approve_line_contact($userId)` がフェーズを判定して分岐：
  - **無料フェーズ**：`provision_free_member_from_contact()` が決済なしで会員資格（active・初回PW強制変更）を発行し、ID/PWをLINE/メールで配布。冪等（連絡先の member_id で二重発行を防止）。
  - **課金フェーズ**：従来どおり決済リンクを送信。
- CLI: `php bin/console.php approve-contact <line_user_id>` も同じ分岐で動作。

**紹介特典＝月額無料化（#19・動的・A案採用）**
- 判定モード（`app_settings.referral_waiver_mode`・既定 **A**・運営ダッシュボードでトグル）：
  - **A案（拡散重視）**：無料化した紹介先(active)も5名に数える。
  - **B案（収益重視）**：実際に課金している紹介先(active かつ `subscription_waived=0`)だけ数える。B案は「無料1名につき下に有料5名」が必ず成立するため、**有料比率 ≧ 5/6（約83%）が構造的に保証**される。
- `evaluate_referral_waiver()`（cron: `bin/referral_waiver.php`・1日1回）：
  - active サブスク会員ごとに `count_active_referrals()` を数え、`REFERRAL_WAIVER_MIN`（既定5）以上なら Stripe 100%OFFクーポンを適用（`subscription_waived=1`）、割り込んだら解除（`=0`）。
  - `subscription_waived` フラグで Stripe への二重適用を防止（冪等）。無料フェーズは即スキップ。
  - 100%割引でも `subscription_status='active'` は維持されるため、無料化会員はアクセス制限にかからない。
- **無料比率モニター**：運営ダッシュボードに「課金中／無料化／無料比率(%)」を表示。無料比率が高すぎる場合はB案切替を促す警告を表示。
