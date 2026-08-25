# セキュリティ対応サマリー

> 本資料は、イベント事前決済アプリに対して実施したセキュリティ対応の**総まとめ**です。
> 詳細な脅威分析は `docs/security-threat-analysis.md` を参照してください。
>
> 対象ブランチ: `claude/loving-hawking-m22ci7` ／ 最終更新: 2026-06-12
> 検証: ローカル（PHP 8.4 ビルトインサーバ／実 Chrome by Playwright）で実施。**本番（event.coresv.com）には未反映**。

---

## 0. 方針（オーナー要件）

- **マルチテナントを維持**（誰でも主催者になれる）。
- **各主催者が自分のデータを自己責任で管理**する。
- **プラットフォーム管理者は情報を極力持たない／見えない**。
- システムが保持せざるを得ない情報は**堅牢に保護**し、管理者も閲覧できない状態を理想とする。

→ これを満たす設計として **Stripe Connect による物理分離 ＋ システムDBは参加者PIIを非保持 ＋ 管理者は横断閲覧UIなし** を採用。

---

## 1. 実施した対応一覧（カテゴリ別）

### A. 入力・出力の安全化
| 項目 | 対応 | 主な変更 |
|---|---|---|
| CSV 数式インジェクション | 参加者由来フィールド（氏名・メール・電話・備考・ID）を `csv_cell()` で無害化（先頭 `= + - @` Tab CR に `'`） | `src/bootstrap.php`, `public/admin/export.php` |
| 出力エスケープ | 既存の `e()`（htmlspecialchars）を維持。確認ダイアログは `data-confirm`＋`e()` へ | 各テンプレート |

### B. 認可（アクセス制御）
| 項目 | 対応 | 主な変更 |
|---|---|---|
| テナント間 IDOR | 出席/集金/当日取消/返金で、対象 `customer_id`/`payment_intent` が**そのイベントの参加者か**を Stripe 側で検証 | `find_event_participant_by_customer/by_payment_intent`（`bootstrap.php`）, `attend.php`, `onsite_collect.php`, `onsite_cancel.php`, `refund.php` |
| 物理分離（Connect） | 主催者ごとに自分の Stripe を OAuth 接続。決済・名簿・入金をアカウント単位で分離 | `connect.php`, `bootstrap.php`, 公開/管理の全 Stripe 呼び出し |

### C. 認証・セッション
| 項目 | 対応 | 主な変更 |
|---|---|---|
| ログイン強化 | CAPTCHA 適用（**fail-closed**）＋**メール単位＋IP単位**の失敗回数制限＋**タイミング平準化**（アカウント列挙対策） | `login.php`, `recent_failed_logins_by_ip`/`login_tenant`（`tenant.php`） |
| パスワード強度 | 8文字以上＋よくある弱PW・同一文字反復を拒否。登録/変更/再設定に適用 | `assert_password_strength`（`tenant.php`）, `reset.php` |
| Cookie/セッション | `Secure` を HTTPS 配信時に常時付与＋アイドルタイムアウト30分＋ログイン時 ID 再生成 | `tenant.php`（`session_boot`）, `bootstrap.php`（`request_is_https`） |

### D. 濫用・DoS 対策
| 項目 | 対応 | 主な変更 |
|---|---|---|
| 未認証フォームのレート制限 | signup(5/h)・forgot(5/h)・apply=checkout(20/h) を IP 単位で制限 | `rate_limit_check`＋`rate_events` 表（`tenant.php`/`db.php`）, 各エンドポイント |
| CAPTCHA | Cloudflare Turnstile（キー設定時のみ有効・未設定は素通り）を signup/forgot/apply/login に。signup/login は**到達不可時 fail-closed** | `src/captcha.php`, 各フォーム |
| 公開GETの増幅防止 | `apply.php`/`o.php` の残席算定を **headcount_cache（60秒）** 経由化＋IP単位レート制限 | `event_headcount_cached`（`bootstrap.php`）, `headcount_cache` 表, `apply.php`, `o.php` |
| IP判定の正確化 | `TRUSTED_PROXY=1` 時のみ `X-Forwarded-For` を採用（プロキシ配下の誤ロック/回避を是正） | `client_ip()`（`tenant.php`） |
| 表メンテ | `rate_events`/`headcount_cache` の確率的掃除＋イベント削除時のキャッシュ除去 | `tenant.php`, `bootstrap.php` |

### E. データ保護・ログ
| 項目 | 対応 | 主な変更 |
|---|---|---|
| Stripe鍵の保存 | 主催者の鍵は**DBに保存せず公開フォルダ外のファイル**に保存。**保存先が公開フォルダ内なら保存自体を拒否**（Web直DL＝窃取を構造的に防止）。APP_KEY があれば暗号化も併用（任意）。加えて管理画面で重大警告 | `set_tenant_stripe_key`（`tenant.php`・docroot内は例外）, `security_warnings`（`bootstrap.php`） |
| 参加者PIIの非保持 | 参加者情報はシステムDBに持たず、各主催者の Stripe にのみ存在（Connect） | 設計 |
| mail.log のPII最小化 | 本文・トークン・完全アドレスを記録せず、**マスク宛先＋件名（イベント名も伏字）＋送信可否**のみ（`MAIL_LOG=0` で無効化）＋サイズローテーション | `src/mail.php` |
| 決済成功画面のPII | `success.php` の購入者メールをマスク表示（session_id 漏洩時の露出を低減） | `public/success.php` |
| ファイル直アクセス遮断 | `.env`・DB・ログの直配信を拒否する `.htaccess`（ルート/`logs/`/実行時生成の `data/`） | `.htaccess`, `logs/.htaccess`, `db.php` |

### F. ヘッダ・フロント堅牢化
| 項目 | 対応 | 主な変更 |
|---|---|---|
| セキュリティヘッダ | CSP（`object-src 'none'` 含む）/ X-Frame-Options: DENY / X-Content-Type-Options / Referrer-Policy / Permissions-Policy / COOP / CORP / HTTPS時 HSTS を全レスポンスに付与 | `send_baseline_security_headers`（`bootstrap.php`） |
| CSP 厳格化 | `script-src` から `'unsafe-inline'` を撤廃（**nonce 化**＋Chart.js 自己ホスト＋インラインハンドラ11箇所を全廃し `app.js` へ集約）。`<style>` も nonce 必須 | `bootstrap.php`, `public/assets/app.js`, `public/assets/chart.umd.min.js`, 各テンプレート |

### G. 運用ドキュメント
| 項目 | 対応 |
|---|---|
| 脅威分析 | `docs/security-threat-analysis.md`（漏洩リスク・攻撃者視点2ラウンド・対策・優先度・データガバナンス・鍵ローテーション手順） |
| 本資料 | 対応総まとめ（このファイル） |

---

## 2. 新規追加された設定（`.env`）

| 変数 | 既定 | 用途 |
|---|---|---|
| `STRIPE_CONNECT_CLIENT_ID` | 空 | Connect の client_id（ca_...）。設定で物理分離を有効化。未設定は従来のプラットフォーム鍵運用（後方互換） |
| `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` | 空 | CAPTCHA。両方設定で有効化、未設定は素通り |
| `ALLOW_SIGNUP` | `0` | 運営アカウントのオープン登録の受付可否。既定は停止。開ける場合のみ `1` を明示する |
| `MAIL_LOG` | `1` | メール監査ログの出力可否（`0` で無効） |
| `MAIL_LOG_MAX_BYTES` | `5242880` | mail.log ローテーション上限 |
| `TRUSTED_PROXY` | `0` | `1` のとき送信元IP判定に `X-Forwarded-For` を採用（信頼プロキシ背後でのみ）。直接公開時は `0` |

### 意図的に未実装（要件）
- **プラン課金（ペイウォール）の強制**: 現状は**無料運用**方針のため未実装。将来サブスク化／「フリー＝累計N件」導入時に `event_save.php` へガード追加で有効化（課金導線=billing は別途設計）。セキュリティ脆弱性ではない。

---

## 3. 検証結果（ローカル）

- 変更 PHP ファイルは全て `php -l` で構文エラーなし。
- セキュリティロジック単体テスト **15/15 pass**（CSV無害化・パスワード強度・トークンマスク・https判定・レート制限・テーブル生成）。
- 実 Chrome（Playwright）で apply/login/policy を検証 → **CSP違反・JSエラー 0件**、合計計算・支払い方法切替が正常動作。
- ログイン（CAPTCHA無効時）→ 302→dashboard 正常。Connect 有効/無効でダッシュボード表示切替を確認。
- mail.log に本文・氏名・完全アドレスが残らないことを確認（マスク宛先のみ）。

---

## 4. 残る信頼境界・運用で担保する項目（コードでは閉じない）

| # | 項目 | 対策（運用） |
|---|---|---|
| B | サーバ/`.env`/バックアップ奪取で全Connectデータにアクセスされ得る | Stripe **制限付きキー**で権限最小化、横断取得コードを足さない、バックアップ暗号化、鍵ローテーション（脅威分析§9） |
| D | 共有ホスティング（CoreServer）のセッションファイル覗き見 | `session.save_path` を専用ディレクトリへ、適切なパーミッション、可能なら専用環境 |
| F | OAuth 連携先すり替え（ソーシャル） | 手順の明示・サポート詐称への注意喚起、接続後アカウントの確認 |
| — | docroot を必ず `public/` に固定 | サーバー設定で担保（`.htaccess` は保険） |
| — | サプライチェーン（composer 依存・自己ホストJS） | lock 固定、`composer audit`、更新時レビュー |

---

## 5. 本番反映前のチェックリスト

- [ ] `.env` に必要キーを設定（`STRIPE_SECRET_KEY`、必要なら `STRIPE_CONNECT_CLIENT_ID`、`TURNSTILE_*`、`MAIL_*`、`APP_BASE_URL=https://...`）
- [ ] ドキュメントルートが `public/` であることを確認
- [ ] `composer install`（`vendor/` 配置）
- [ ] Connect を使う場合: Stripe で Connect 有効化 → **テスト接続→決済→名簿→返金**の通し確認
- [ ] CAPTCHA を使う場合: Turnstile キー設定後、各フォームでウィジェット表示と検証を確認
- [ ] 既存運用者（共有鍵で稼働中）の移行方針を決定（Connect 接続を促す/後方互換のまま）
- [ ] バックアップの暗号化・保管先を確認
