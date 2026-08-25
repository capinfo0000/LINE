<?php

/**
 * 運営コンソール用のヘルパー（会員管理・写真モデレーション・タグ管理・一斉配信・統計）。
 */

declare(strict_types=1);

/* ------------------------- 会員管理 ------------------------- */

/**
 * 会員を検索する（管理用）。キーワードは login_id / email / 表示名 / プロフィール名。
 *
 * @return array<int,array>
 */
function admin_search_members(string $keyword = '', string $status = '', int $limit = 200): array
{
    $where = [];
    $params = [];
    if ($status !== '') {
        $where[] = 'm.status = :status';
        $params[':status'] = $status;
    }
    if (trim($keyword) !== '') {
        $where[] = '(m.login_id LIKE :kw OR m.email LIKE :kw OR m.display_name LIKE :kw OR p.name_text LIKE :kw)';
        $params[':kw'] = '%' . trim($keyword) . '%';
    }
    $sql = 'SELECT m.*, p.name_text, p.photo_status
              FROM members m LEFT JOIN profiles p ON p.member_id = m.id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY m.created_at DESC LIMIT ' . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** 会員の入金履歴を返す。 */
function member_payments(string $memberId): array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE member_id = ? ORDER BY created_at DESC');
    $stmt->execute([$memberId]);
    return $stmt->fetchAll();
}

/** 会員ステータスを変更する（active/suspended/cancelled 等）。 */
function admin_set_member_status(string $memberId, string $status): bool
{
    $allowed = ['lead', 'pending_payment', 'active', 'suspended', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $stmt = db()->prepare('UPDATE members SET status = ? WHERE id = ?');
    $stmt->execute([$status, $memberId]);
    return $stmt->rowCount() > 0;
}

/**
 * 会員の資格情報を再発行する（ログインIDは維持、仮パスワードを再生成・初回変更フラグを立てる）。
 * 生成した仮パスワードで配布まで行い、平文は保持しない。
 *
 * @return bool 配布できたら true
 */
function admin_reissue_credentials(string $memberId): bool
{
    $member = find_member_by_id($memberId);
    if ($member === null) {
        return false;
    }
    $temp = generate_temp_password();
    $stmt = db()->prepare('UPDATE members SET password_hash = ?, must_change_pw = 1 WHERE id = ?');
    $stmt->execute([password_hash($temp, PASSWORD_DEFAULT), $memberId]);

    // LINE 再配布のため、配布済みフラグをリセット（本人に紐づく contact があれば）。
    if (!empty($member['line_user_id'])) {
        $r = db()->prepare('UPDATE line_contacts SET credentials_sent = 0 WHERE line_user_id = ?');
        $r->execute([$member['line_user_id']]);
    }
    audit_log('admin.reissue_credentials', ['member' => $memberId]);
    $fresh = find_member_by_id($memberId);
    return deliver_member_credentials($fresh, (string) $fresh['login_id'], $temp);
}

/* ------------------------- タグマスタ管理 ------------------------- */

/** タグを追加する（既存は無視）。 */
/**
 * タグを追加する。
 * 追加できなかった理由（未入力・不正な分類・重複）を呼び出し側に返し、
 * 画面で「作成しました」と誤って表示しないようにする。
 *
 * @return array{ok:bool, message:string}
 */
function admin_add_tag(string $categoryKey, string $label): array
{
    $categoryKey = trim($categoryKey);
    $label = clean_line_text($label);
    if ($label === '') {
        return ['ok' => false, 'message' => 'タグ名が未入力です。追加する名前を入力してください。'];
    }
    if (mb_strlen($label) > 40) {
        return ['ok' => false, 'message' => 'タグ名が長すぎます。40文字以内にしてください。'];
    }
    $cats = array_column(get_tag_categories(), 'key');
    if ($categoryKey === '' || !in_array($categoryKey, $cats, true)) {
        return ['ok' => false, 'message' => '分類が正しくありません。一覧から選び直してください。'];
    }
    $dup = db()->prepare('SELECT 1 FROM tags WHERE category_key = ? AND label = ? LIMIT 1');
    $dup->execute([$categoryKey, $label]);
    if ($dup->fetchColumn()) {
        return ['ok' => false, 'message' => '「' . $label . '」は既に登録されています。'];
    }
    $stmt = db()->prepare('INSERT INTO tags (category_key, label, sort) VALUES (?,?,?)');
    $stmt->execute([$categoryKey, $label, 999]);
    audit_log('admin.tag_added', ['category' => $categoryKey, 'label' => $label]);
    return ['ok' => true, 'message' => 'タグ「' . $label . '」を作成しました。'];
}

/** タグの有効/無効を切り替える。 */
function admin_set_tag_active(int $tagId, bool $active): void
{
    $stmt = db()->prepare('UPDATE tags SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $tagId]);
}

/**
 * 各種サイト設定（特商法表記・ポリシー等）のキー定義。
 * ラベル・入力種別（text/textarea）・初期値・補足を持つ。
 *
 * @return array<string,array{label:string,type:string,default:string,hint:string}>
 */
function site_setting_defs(): array
{
    return [
        'biz_name'    => ['label' => '販売事業者（事業者名／屋号）', 'type' => 'text', 'default' => '', 'hint' => '特定商取引法の表記に使用します。'],
        'biz_owner'   => ['label' => '運営責任者（氏名）', 'type' => 'text', 'default' => '', 'hint' => ''],
        'biz_address' => ['label' => '所在地', 'type' => 'text', 'default' => '', 'hint' => '「請求があれば遅滞なく開示」の運用も可能です。'],
        'biz_email'   => ['label' => '連絡先メールアドレス', 'type' => 'text', 'default' => '', 'hint' => ''],
        'biz_tel'     => ['label' => '連絡先電話番号', 'type' => 'text', 'default' => '', 'hint' => ''],
        'line_official_url' => ['label' => '公式LINEのトークURL', 'type' => 'text', 'default' => '', 'hint' => '例: https://line.me/R/ti/p/@034gqrjg　会員の「自己紹介を送る」画面に「公式LINEを開く」ボタンが表示されます。'],
        'price_note'  => ['label' => '販売価格の表記', 'type' => 'textarea', 'default' => "月額会費 500円（税込）／月。\n※会員数が一定数に達するまでは無料でご利用いただけます。\n※ご紹介の特典条件を満たす場合、月額会費が無料となることがあります。", 'hint' => ''],
        'cancel_note' => ['label' => 'キャンセル・返金ポリシーの追記', 'type' => 'textarea', 'default' => '', 'hint' => '本文の後に「補足」として追記されます（空欄可）。本文そのものは「規約・ポリシー」で編集します。'],
        'privacy_note' => ['label' => 'プライバシーポリシーの追記', 'type' => 'textarea', 'default' => '', 'hint' => '本文の後に「補足」として追記されます（空欄可）。本文そのものは「規約・ポリシー」で編集します。'],
        'terms_note'  => ['label' => '利用規約の追記', 'type' => 'textarea', 'default' => '', 'hint' => '本文の後に「補足」として追記されます（空欄可）。本文そのものは「規約・ポリシー」で編集します。'],
    ];
}

/* ---------------- 運用モードに連動する公開文面 ----------------
 * 「初期運用／通常運用」「自己紹介ロック」「無料／課金フェーズ」を管理画面で切り替えると、
 * 規約・プライバシーポリシー・案内文の記述も一緒に変わるようにする。
 * 切り替えたのに文面が古いまま、という食い違いを起こさないための共通関数。
 */

/** 入会の流れの説明。登録運用モード（signup_mode）に連動する。 */
function ops_signup_description(): string
{
    return signup_mode() === 'auto'
        ? '公式LINEを友だち追加いただくと、その場で会員登録が完了し、会員サイトのログインID・仮パスワードをLINEでお送りします。'
        : '公式LINEを友だち追加のうえ、当方所定の手続き（説明会・個別面談等）を経てお申し込みいただきます。お申し込み後、会員サイトのログインID・仮パスワードをお送りします。';
}

/** 入会前に取得する情報の説明。説明会・面談の有無が運用モードで変わる。 */
function ops_precontract_data_description(): string
{
    return signup_mode() === 'auto'
        ? '公式LINEでのやり取り（トーク内容・表示名・LINEのユーザー識別子）。'
        : '公式LINEでのやり取り（トーク内容・表示名・LINEのユーザー識別子）、説明会・個別面談の予約情報。';
}

/** 料金の説明。無料フェーズ中か、課金開始後かで変わる。 */
function ops_billing_description(): string
{
    if (billing_started()) {
        return '会員資格の維持には、月額会費（税込500円・サブスクリプション）のお支払いが必要です。';
    }
    return '現在は無料でご利用いただけます。会員数が' . billing_free_limit() . '名を超えた以降は、会員資格の維持に月額会費（税込500円・サブスクリプション）が必要になります。';
}

/** 自己紹介ロックの説明。OFF のときは空文字（＝その条文自体を出さない）。 */
function ops_intro_gate_description(): string
{
    return intro_gate_enabled()
        ? '会員検索（さがす）のご利用にあたっては、公式LINEのトークに自己紹介をお送りいただく必要があります。送信を確認した時点で自動的にご利用いただけるようになります。'
        : '';
}

/**
 * 会員に見せる「会員ステータス」の表記。
 *
 * 課金が始まるまでは全員が同じ条件なので、プラン名（プレミアム等）は出さない。
 * 初期運用の間は「先行メンバー」と呼ぶ。「初期運用」は運営側の言葉で会員には
 * 伝わらないうえ、「無料会員」だと下の扱いに見えてしまうため。課金開始後は
 * 「先行メンバー → プレミアム」と自然につながる。
 */
function ops_member_status_label(array $member): string
{
    if (billing_started()) {
        return plan_label(member_plan($member));
    }
    return signup_mode() === 'auto' ? '先行メンバー' : '無料期間中';
}

/** ログインID・パスワードの受け取り方の案内。登録運用モードに連動する。 */
function ops_credentials_description(): string
{
    return signup_mode() === 'auto'
        ? 'ログインID・仮パスワードは、公式LINEを友だち追加された際にLINEでお送りしています。'
        : 'ログインID・仮パスワードは、入会手続きの完了時に公式LINEでお送りしています。';
}

/**
 * 規約・ポリシーで使う事業者名。未設定なら「（未設定）」を返す。
 * $html=true でグレー表示のマークアップ付き（特商法ページと同じ見え方）。
 */
function legal_biz_name(bool $html = false): string
{
    $name = site_setting('biz_name');
    if ($name !== '') {
        return $html ? e($name) : $name;
    }
    return $html ? '<span class="muted">（未設定）</span>' : '（未設定）';
}

/** 事業者情報が未入力か（公開ページに注意書きを出すかの判定）。 */
function legal_biz_incomplete(): bool
{
    return site_setting('biz_name') === '' || site_setting('biz_owner') === '';
}

/** サイト設定の値を取得（未設定なら定義の初期値）。 */
function site_setting(string $key): string
{
    $defs = site_setting_defs();
    $default = (string) ($defs[$key]['default'] ?? '');
    $v = app_setting_get('site_' . $key, null);
    return ($v === null || $v === '') ? $default : $v;
}

/** サイト設定の保存（定義済みキーのみ）。 */
function site_setting_save(array $input): int
{
    $n = 0;
    foreach (site_setting_defs() as $key => $def) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $val = (string) $input[$key];
        $val = $def['type'] === 'textarea' ? mb_substr($val, 0, 8000) : mb_substr(trim($val), 0, 300);
        app_setting_set('site_' . $key, $val);
        $n++;
    }
    audit_log('admin.site_settings_saved', ['count' => $n]);
    return $n;
}

/**
 * 一括付与（ポイント・プラン・称号）を、指定した会員へまとめて適用する。
 *
 * 取り返しがつきにくい操作なので、対象が空なら何もせず理由を返す。
 * どの操作も監査ログに件数を残す。
 *
 * @param string[] $memberIds
 * @return array{ok:bool, message:string}
 */
function admin_bulk_grant(array $memberIds, string $op, array $params): array
{
    $memberIds = array_values(array_filter(array_map('strval', $memberIds), static fn ($v) => $v !== ''));
    if ($memberIds === []) {
        return ['ok' => false, 'message' => '対象の会員がいません。検索条件を確認してください。'];
    }

    if ($op === 'points') {
        $delta = (int) ($params['delta'] ?? 0);
        if ($delta === 0) {
            return ['ok' => false, 'message' => '増減するポイント数を入力してください（0 では変更されません）。'];
        }
        $note = mb_substr(clean_line_text((string) ($params['note'] ?? '')), 0, 200);
        foreach ($memberIds as $mid) {
            add_points($mid, $delta, 'admin_adjust', null, $note);
        }
        $n = count($memberIds);
        audit_log('admin.bulk_points', ['count' => $n, 'delta' => $delta]);
        return ['ok' => true, 'message' => "{$n}名に " . ($delta > 0 ? '+' : '') . "{$delta}pt を付与しました。"];
    }

    if ($op === 'plan') {
        $plan = (string) ($params['plan'] ?? '');
        if (!in_array($plan, ['basic', 'premium'], true)) {
            return ['ok' => false, 'message' => 'プランが正しくありません。一覧から選び直してください。'];
        }
        foreach ($memberIds as $mid) {
            set_member_plan($mid, $plan);
        }
        $n = count($memberIds);
        audit_log('admin.bulk_plan', ['count' => $n, 'plan' => $plan]);
        return ['ok' => true, 'message' => "{$n}名のプランを" . plan_label($plan) . 'に変更しました。'];
    }

    if ($op === 'title') {
        $title = trim((string) ($params['title'] ?? ''));
        if ($title !== '' && !in_array($title, assignable_titles(), true)) {
            return ['ok' => false, 'message' => '称号が正しくありません。一覧から選び直してください。'];
        }
        $n = 0;
        foreach ($memberIds as $mid) {
            $r = set_member_title($mid, $title);
            if ($r['ok']) {
                $n++;
            }
        }
        audit_log('admin.bulk_title', ['count' => $n, 'title' => $title]);
        return ['ok' => true, 'message' => $title === ''
            ? "{$n}名の称号をポイント連動（自動）に戻しました。"
            : "{$n}名の称号を「{$title}」に設定しました。"];
    }

    return ['ok' => false, 'message' => '操作の種類が不明です。'];
}

/**
 * タグを削除する。会員に付いている紐付け・求める条件からも取り除く。
 * @return bool 削除できたら true
 */
function admin_delete_tag(int $tagId): bool
{
    if ($tagId <= 0) {
        return false;
    }
    // 会員のタグ紐付けを解除。
    db()->prepare('DELETE FROM member_tags WHERE tag_id = ?')->execute([$tagId]);
    // 求める条件（JSON配列で保持）からも該当IDを除去。
    $rows = db()->query('SELECT member_id, seek_area, seek_job, seek_purpose FROM match_preferences')->fetchAll();
    $upd = db()->prepare('UPDATE match_preferences SET seek_area = ?, seek_job = ?, seek_purpose = ? WHERE member_id = ?');
    foreach ($rows as $r) {
        $changed = false;
        $vals = [];
        foreach (['seek_area', 'seek_job', 'seek_purpose'] as $col) {
            $ids = json_decode((string) ($r[$col] ?? '[]'), true);
            $ids = is_array($ids) ? array_map('intval', $ids) : [];
            $filtered = array_values(array_filter($ids, static fn ($v) => $v !== $tagId));
            if (count($filtered) !== count($ids)) {
                $changed = true;
            }
            $vals[$col] = json_encode($filtered);
        }
        if ($changed) {
            $upd->execute([$vals['seek_area'], $vals['seek_job'], $vals['seek_purpose'], $r['member_id']]);
        }
    }
    $stmt = db()->prepare('DELETE FROM tags WHERE id = ?');
    $stmt->execute([$tagId]);
    audit_log('admin.tag_deleted', ['tag' => $tagId]);
    return $stmt->rowCount() > 0;
}

/**
 * 会員を完全に削除する（元に戻せない）。プロフィール・タグ・ポイント・評価・足あと等の
 * 関連データと顔写真/カバー/名刺の画像ファイルも削除し、LINE連絡先の紐付けを解除する。
 *
 * @return bool 削除できたら true
 */
function admin_delete_member(string $memberId): bool
{
    if ($memberId === '') {
        return false;
    }
    if (find_member_by_id($memberId) === null) {
        return false;
    }

    // 画像ファイル（公開領域外）を先に削除。
    $profile = get_profile($memberId);
    foreach (['photo_path', 'cover_path', 'card_path'] as $col) {
        $abs = member_image_abs_path($profile, $col);
        if ($abs !== null) {
            @unlink($abs);
        }
    }

    // member_id 列で紐づくテーブル。
    foreach (['profiles', 'member_tags', 'match_preferences', 'member_links', 'point_ledger', 'recommendations'] as $tbl) {
        try {
            db()->prepare("DELETE FROM {$tbl} WHERE member_id = ?")->execute([$memberId]);
        } catch (\Throwable $e) {
            // テーブルが無い場合は無視。
        }
    }
    // ペア列（双方向の関係データ）。
    $pairs = [
        'member_interests'   => ['from_id', 'to_id'],
        'member_views'       => ['from_id', 'to_id'],
        'member_evaluations' => ['rater_id', 'target_id'],
        'referrals'          => ['referrer_id', 'joiner_id'],
        'referral_payouts'   => ['referrer_id', 'joiner_id'],
    ];
    foreach ($pairs as $tbl => $cols) {
        foreach ($cols as $col) {
            try {
                db()->prepare("DELETE FROM {$tbl} WHERE {$col} = ?")->execute([$memberId]);
            } catch (\Throwable $e) {
                // テーブル・列が無い場合は無視。
            }
        }
    }
    // LINE連絡先の紐付けを解除（連絡先自体は残す）。
    db()->prepare('UPDATE line_contacts SET member_id = NULL WHERE member_id = ?')->execute([$memberId]);

    $stmt = db()->prepare('DELETE FROM members WHERE id = ?');
    $stmt->execute([$memberId]);
    audit_log('admin.member_deleted', ['member' => $memberId]);
    return $stmt->rowCount() > 0;
}

/* ------------------------- 一斉配信（LINE Push） ------------------------- */

/** 一斉配信の宛先数（active 会員で line_user_id を持つ人数）＝推定課金通数。 */
function broadcast_recipient_count(): int
{
    return (int) db()->query(
        "SELECT COUNT(*) FROM members WHERE status = 'active' AND line_user_id IS NOT NULL AND line_user_id != ''"
    )->fetchColumn();
}

/**
 * active 会員（line_user_id 保有）へ Push 一斉配信する。宛先1件ごとに課金。
 *
 * @return int 送信できた件数
 */
function broadcast_push(string $text): int
{
    $rows = db()->query(
        "SELECT line_user_id FROM members WHERE status = 'active' AND line_user_id IS NOT NULL AND line_user_id != ''"
    )->fetchAll();
    $sent = 0;
    foreach ($rows as $r) {
        if (line_push((string) $r['line_user_id'], [line_text($text)])) {
            $sent++;
        }
    }
    audit_log('admin.broadcast', ['recipients' => count($rows), 'sent' => $sent]);
    return $sent;
}

/* ------------------------- 統計 ------------------------- */

/** ダッシュボード用の集計。 */
function admin_stats(): array
{
    $one = static fn (string $sql) => (int) db()->query($sql)->fetchColumn();
    return [
        'members_total'   => $one('SELECT COUNT(*) FROM members'),
        'members_active'  => $one("SELECT COUNT(*) FROM members WHERE status = 'active'"),
        'payments_paid'   => $one("SELECT COUNT(*) FROM payments WHERE status = 'paid'"),
        'revenue'         => $one("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid'"),
        'line_contacts'   => $one('SELECT COUNT(*) FROM line_contacts'),
        'upcoming_bookings' => $one('SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.status = "booked" AND s.start_at > ' . time()),
        'push_this_month' => $one("SELECT COUNT(*) FROM line_messages WHERE billable = 1 AND created_at >= strftime('%s', date('now','+9 hours','start of month','-9 hours'))"),
    ];
}
