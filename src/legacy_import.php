<?php

/**
 * 旧サイト（別ドメインに置いた古いコピー）から、会員1名分を移してくるための取り込み処理。
 *
 * 経緯：ドメインを移したとき、旧フォルダにアプリの実体が残ったままになっていた。
 * 実体が2つあるとデータベースも2つになり、古いリンクから入った人が
 * 旧サイト側に登録されてしまう。実際に1名が旧サイト側に登録されたため、
 * その会員（ログイン情報・プロフィール・写真）を新サイトへ持ってくる。
 *
 * 受け渡しは1つのJSONファイルで行う。data/legacy/members.json に置いてから
 *   cron.php?job=legacy_scan&token=…    何が入っていて、取り込めるかを見る（書き込まない）
 *   cron.php?job=legacy_import&token=…  実際に取り込む
 * を叩く。data/ は公開領域の外なので、置いたファイルがURLから読まれることはない。
 *
 * 設計上の注意
 * - 会員ID・ログインID・パスワードのハッシュは引き継ぐ。本人が今のIDとパスワードで
 *   そのまま新サイトにログインできるようにするため（作り直すと連絡が必要になる）。
 * - タグは id ではなく「分類キー＋名前」で照合する。tags.id は各データベースが
 *   独自に採番するので、旧DBの 12 番が新DBの 12 番と同じ意味とは限らない。
 *   「求める条件」も同じ理由で名前から引き直す。
 * - 他の会員を指す行（気になる・足あと・おすすめ）は持ってこない。
 *   相手が新DBに居らず、意味のない行になるため。
 * - 何度叩いても同じ結果になるようにする（既に居る会員は飛ばす）。
 */

declare(strict_types=1);

/** 受け渡しファイルの形式名。取り違え防止のため中に書いておく。 */
const LEGACY_PAYLOAD_FORMAT = 'enlink-legacy-member/1';

/** 画像1枚の上限（デコード後）。壊れた・巨大なファイルで詰まらせないため。 */
const LEGACY_IMAGE_MAX_BYTES = 12 * 1024 * 1024;

/** 受け渡しファイルの置き場所。 */
function legacy_payload_path(): string
{
    return dirname(current_db_path()) . '/legacy/members.json';
}

/**
 * 受け渡しファイルを読む。
 *
 * @return array{ok:bool, message:string, members:array<int,array>}
 */
function legacy_read_payload(): array
{
    $path = legacy_payload_path();
    if (!is_file($path)) {
        return ['ok' => false, 'message' => '受け渡しファイルがありません: data/legacy/members.json', 'members' => []];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'message' => '受け渡しファイルを読めませんでした。', 'members' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => '受け渡しファイルの中身がJSONとして読めませんでした。', 'members' => []];
    }
    if (($data['format'] ?? '') !== LEGACY_PAYLOAD_FORMAT) {
        return ['ok' => false, 'message' => '受け渡しファイルの形式が違います（format が ' . LEGACY_PAYLOAD_FORMAT . ' ではありません）。', 'members' => []];
    }
    $members = $data['members'] ?? null;
    if (!is_array($members) || $members === []) {
        return ['ok' => false, 'message' => '受け渡しファイルに会員が入っていません。', 'members' => []];
    }
    return ['ok' => true, 'message' => '', 'members' => array_values($members)];
}

/**
 * 「分類キー＋名前」からタグIDを引く。見つからなければ null。
 * 新DBに無いタグは作らない（勝手にタグ一覧が増えると運営が把握できなくなる）。
 */
function legacy_resolve_tag(string $categoryKey, string $label): ?int
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (db()->query('SELECT id, category_key, label FROM tags') as $r) {
            $map[(string) $r['category_key'] . "\0" . (string) $r['label']] = (int) $r['id'];
        }
    }
    return $map[$categoryKey . "\0" . $label] ?? null;
}

/**
 * 紹介コードをそのまま使えるか確かめ、既に使われていたら新しく発行する。
 *
 * referral_code / public_code は部分UNIQUEなので、旧DBと新DBでたまたま同じ値が
 * 出ていると取り込み全体が落ちる（実際にテストで落ちた）。1名の移行が
 * コードの偶然の衝突で止まるのは筋が悪いので、衝突したほうを振り直す。
 * 旧サイトで配った紹介コードは使えなくなるが、紹介URLは旧サイト向けのもので
 * どうせ機能しないため、実害はない。
 *
 * @return array{code:?string, reissued:bool}
 */
function legacy_safe_referral_code(?string $code): array
{
    if ($code === null || $code === '') {
        return ['code' => generate_referral_code(), 'reissued' => false];
    }
    if (!referral_code_exists($code)) {
        return ['code' => $code, 'reissued' => false];
    }
    return ['code' => generate_referral_code(), 'reissued' => true];
}

/**
 * 共有コード（/u/<コード>）も同じ扱い。旧DBに無ければ発行し、衝突していれば振り直す。
 *
 * @return array{code:string, reissued:bool}
 */
function legacy_safe_public_code(?string $code): array
{
    if ($code === null || $code === '') {
        return ['code' => generate_member_public_code(), 'reissued' => false];
    }
    if (!public_code_exists($code)) {
        return ['code' => $code, 'reissued' => false];
    }
    return ['code' => generate_member_public_code(), 'reissued' => true];
}

/**
 * 取り込み前の下見。何が入っていて、取り込めるか・既に居るかを文章で返す。
 * 書き込みは一切しない。
 */
function legacy_scan(): string
{
    $p = legacy_read_payload();
    if (!$p['ok']) {
        return $p['message'] . "\n";
    }
    $out = [];
    $out[] = '受け渡しファイル: ' . legacy_payload_path();
    $out[] = '入っている会員: ' . count($p['members']) . ' 名';
    $out[] = '';

    foreach ($p['members'] as $i => $m) {
        $mem = $m['member'] ?? [];
        $id = (string) ($mem['id'] ?? '');
        $loginId = (string) ($mem['login_id'] ?? '');
        $out[] = '[' . ($i + 1) . '] ' . ($loginId !== '' ? $loginId : '(ログインIDなし)') . ' / ' . $id;

        if ($id === '' || $loginId === '') {
            $out[] = '    → 取り込めません（会員IDかログインIDが空）';
            continue;
        }
        $byId = db()->prepare('SELECT 1 FROM members WHERE id = ?');
        $byId->execute([$id]);
        $byLogin = db()->prepare('SELECT 1 FROM members WHERE login_id = ?');
        $byLogin->execute([$loginId]);
        if ($byId->fetchColumn() || $byLogin->fetchColumn()) {
            $out[] = '    → 既にこの会員は新サイトに居ます（飛ばします）';
            continue;
        }

        $prof = $m['profile'] ?? [];
        $out[] = '    名前: ' . (((string) ($prof['name_text'] ?? '')) !== '' ? (string) $prof['name_text'] : '(未入力)');
        $out[] = '    自己紹介: ' . mb_strlen((string) ($prof['bio'] ?? '')) . ' 文字'
               . ' / ひとこと: ' . mb_strlen((string) ($prof['headline'] ?? '')) . ' 文字';

        $imgs = is_array($m['images'] ?? null) ? $m['images'] : [];
        $names = [];
        foreach (['photo' => '顔写真', 'cover' => 'カバー', 'card' => '名刺'] as $k => $label) {
            if (isset($imgs[$k]['b64'])) {
                $bytes = strlen((string) base64_decode((string) $imgs[$k]['b64'], true));
                $names[] = $label . '(' . round($bytes / 1024) . 'KB)';
            }
        }
        $out[] = '    画像: ' . ($names === [] ? 'なし' : implode(' / ', $names));

        // タグの照合結果（名前で引けたか）。
        $hit = 0;
        $miss = [];
        foreach ((array) ($m['tags'] ?? []) as $t) {
            $ck = (string) ($t['category_key'] ?? '');
            $lb = (string) ($t['label'] ?? '');
            if (legacy_resolve_tag($ck, $lb) !== null) {
                $hit++;
            } else {
                $miss[] = $ck . '/' . $lb;
            }
        }
        $out[] = '    タグ: ' . $hit . ' 件を照合できました'
               . ($miss !== [] ? '／新サイトに無いタグ ' . count($miss) . ' 件: ' . implode(', ', $miss) : '');
        $out[] = '    リンク: ' . count((array) ($m['links'] ?? [])) . ' 件'
               . ' / ポイント履歴: ' . count((array) ($m['points'] ?? [])) . ' 件';
        $out[] = '    → 取り込めます';
    }
    $out[] = '';
    $out[] = '実行するには job=legacy_import を叩いてください。';
    return implode("\n", $out) . "\n";
}

/**
 * base64の画像を data/uploads/ に書き出す。書けたら相対パス、無理なら null。
 * 画像として読めないものは書かない（受け渡しファイルが壊れていた場合の保険）。
 */
function legacy_write_image(string $memberId, string $kind, array $img): ?string
{
    $b64 = (string) ($img['b64'] ?? '');
    if ($b64 === '') {
        return null;
    }
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '' || strlen($bin) > LEGACY_IMAGE_MAX_BYTES) {
        return null;
    }
    $info = @getimagesizefromstring($bin);
    if ($info === false) {
        return null;
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) {
        return null;
    }
    // 保存名は新サイトの付け方に合わせる（顔写真は接尾辞なし、他は _cover / _card）。
    $base = $kind === 'photo' ? $memberId : $memberId . '_' . $kind;
    $rel = 'uploads/' . $base . '.' . $ext;
    $abs = dirname(current_db_path()) . '/' . $rel;
    if (@file_put_contents($abs, $bin) === false) {
        return null;
    }
    @chmod($abs, 0600);
    return $rel;
}

/**
 * 取り込みを実行する。会員ごとにトランザクションを張り、途中で失敗したら
 * その会員だけ元に戻す（半端な会員が残らないようにする）。
 */
function legacy_import(): string
{
    $p = legacy_read_payload();
    if (!$p['ok']) {
        return $p['message'] . "\n";
    }
    $out = [];
    $done = 0;
    $skipped = 0;

    foreach ($p['members'] as $m) {
        $mem = is_array($m['member'] ?? null) ? $m['member'] : [];
        $id = (string) ($mem['id'] ?? '');
        $loginId = (string) ($mem['login_id'] ?? '');
        if ($id === '' || $loginId === '' || ((string) ($mem['password_hash'] ?? '')) === '') {
            $out[] = '飛ばしました（会員ID・ログインID・パスワードのいずれかが空）: ' . $loginId;
            $skipped++;
            continue;
        }
        $chk = db()->prepare('SELECT 1 FROM members WHERE id = ? OR login_id = ?');
        $chk->execute([$id, $loginId]);
        if ($chk->fetchColumn()) {
            $out[] = '飛ばしました（既に居ます）: ' . $loginId;
            $skipped++;
            continue;
        }

        db()->beginTransaction();
        try {
            $ref = legacy_safe_referral_code(((string) ($mem['referral_code'] ?? '')) !== '' ? (string) $mem['referral_code'] : null);
            $pub = legacy_safe_public_code(((string) ($mem['public_code'] ?? '')) !== '' ? (string) $mem['public_code'] : null);
            $codeNote = [];
            if ($ref['reissued']) { $codeNote[] = '紹介コードは既に使われていたので振り直しました'; }
            if ($pub['reissued']) { $codeNote[] = '共有URLのコードは既に使われていたので振り直しました'; }

            // --- 会員本体 ---
            db()->prepare(
                'INSERT INTO members
                   (id, login_id, password_hash, must_change_pw, display_name, email, line_user_id,
                    status, approval_state, joined_at, created_at, plan, referral_code, public_code,
                    intro_submitted_at, subscription_waived)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id,
                $loginId,
                (string) $mem['password_hash'],
                (int) ($mem['must_change_pw'] ?? 0),
                (string) ($mem['display_name'] ?? ''),
                ((string) ($mem['email'] ?? '')) !== '' ? (string) $mem['email'] : null,
                ((string) ($mem['line_user_id'] ?? '')) !== '' ? (string) $mem['line_user_id'] : null,
                (string) ($mem['status'] ?? 'active'),
                (string) ($mem['approval_state'] ?? 'approved'),
                (int) ($mem['joined_at'] ?? time()),
                (int) ($mem['created_at'] ?? time()),
                (string) ($mem['plan'] ?? 'basic'),
                $ref['code'],
                $pub['code'],
                ($mem['intro_submitted_at'] ?? null) !== null ? (int) $mem['intro_submitted_at'] : null,
                (int) ($mem['subscription_waived'] ?? 0),
            ]);

            // --- 画像を先に書き出して、保存先をプロフィールに入れる ---
            $imgs = is_array($m['images'] ?? null) ? $m['images'] : [];
            $paths = [];
            $imgNote = [];
            foreach (['photo', 'cover', 'card'] as $kind) {
                $rel = isset($imgs[$kind]) && is_array($imgs[$kind]) ? legacy_write_image($id, $kind, $imgs[$kind]) : null;
                $paths[$kind] = $rel;
                if (isset($imgs[$kind]) && $rel === null) {
                    $imgNote[] = $kind . 'は書き出せませんでした';
                }
            }

            // --- プロフィール ---
            $prof = is_array($m['profile'] ?? null) ? $m['profile'] : [];
            db()->prepare(
                'INSERT INTO profiles
                   (member_id, name_text, age_text, company_title, headline, bio, photo_path, photo_status,
                    visibility_flags, updated_at, birthdate, cover_path, card_path, intro_text, occupation, job_title)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id,
                (string) ($prof['name_text'] ?? ''),
                (string) ($prof['age_text'] ?? ''),
                (string) ($prof['company_title'] ?? ''),
                (string) ($prof['headline'] ?? ''),
                (string) ($prof['bio'] ?? ''),
                $paths['photo'],
                $paths['photo'] !== null ? 'approved' : 'none',
                ((string) ($prof['visibility_flags'] ?? '')) !== ''
                    ? (string) $prof['visibility_flags']
                    : json_encode(['directory' => true, 'line_url' => true]),
                (int) ($prof['updated_at'] ?? time()),
                ((string) ($prof['birthdate'] ?? '')) !== '' ? (string) $prof['birthdate'] : null,
                $paths['cover'],
                $paths['card'],
                (string) ($prof['intro_text'] ?? ''),
                (string) ($prof['occupation'] ?? ''),
                (string) ($prof['job_title'] ?? ''),
            ]);

            // --- タグ（名前で引き直す） ---
            $tagIds = [];
            $tagMiss = [];
            foreach ((array) ($m['tags'] ?? []) as $t) {
                $tid = legacy_resolve_tag((string) ($t['category_key'] ?? ''), (string) ($t['label'] ?? ''));
                if ($tid !== null) {
                    $tagIds[$tid] = true;
                } else {
                    $tagMiss[] = (string) ($t['category_key'] ?? '') . '/' . (string) ($t['label'] ?? '');
                }
            }
            if ($tagIds !== []) {
                $ins = db()->prepare('INSERT OR IGNORE INTO member_tags (member_id, tag_id) VALUES (?,?)');
                foreach (array_keys($tagIds) as $tid) {
                    $ins->execute([$id, $tid]);
                }
            }

            // --- 求める条件（こちらも名前で引き直す） ---
            $prefs = is_array($m['prefs'] ?? null) ? $m['prefs'] : [];
            $prefIds = [];
            foreach (['seek_area', 'seek_job', 'seek_purpose'] as $col) {
                $ids = [];
                foreach ((array) ($prefs[$col] ?? []) as $t) {
                    $tid = legacy_resolve_tag((string) ($t['category_key'] ?? ''), (string) ($t['label'] ?? ''));
                    if ($tid !== null) {
                        $ids[] = $tid;
                    }
                }
                $prefIds[$col] = $ids;
            }
            if ($prefIds['seek_area'] !== [] || $prefIds['seek_job'] !== [] || $prefIds['seek_purpose'] !== []) {
                db()->prepare(
                    'INSERT INTO match_preferences (member_id, seek_area, seek_job, seek_purpose, updated_at) VALUES (?,?,?,?,?)'
                )->execute([
                    $id,
                    json_encode($prefIds['seek_area']),
                    json_encode($prefIds['seek_job']),
                    json_encode($prefIds['seek_purpose']),
                    time(),
                ]);
            }

            // --- リンク ---
            $linkIns = db()->prepare('INSERT INTO member_links (member_id, kind, label, url, sort_order) VALUES (?,?,?,?,?)');
            foreach ((array) ($m['links'] ?? []) as $k => $l) {
                $linkIns->execute([
                    $id,
                    (string) ($l['kind'] ?? 'other'),
                    (string) ($l['label'] ?? ''),
                    (string) ($l['url'] ?? ''),
                    (int) ($l['sort_order'] ?? $k),
                ]);
            }

            // --- ポイント履歴。他の会員を指す ref_member_id は、その会員が新DBに
            //     居ないと意味が無いので落とす（履歴の金額と理由は残す）。 ---
            $ptIns = db()->prepare(
                'INSERT INTO point_ledger (member_id, delta, reason, ref_member_id, note, created_at) VALUES (?,?,?,?,?,?)'
            );
            foreach ((array) ($m['points'] ?? []) as $pt) {
                $ptIns->execute([
                    $id,
                    (int) ($pt['delta'] ?? 0),
                    (string) ($pt['reason'] ?? 'legacy_import'),
                    null,
                    (string) ($pt['note'] ?? ''),
                    (int) ($pt['created_at'] ?? time()),
                ]);
            }

            // --- 公式LINEの友だち紐付け。同じLINE公式アカウントなので引き継ぐ。 ---
            $lineNote = '';
            $lc = is_array($m['line_contact'] ?? null) ? $m['line_contact'] : [];
            $lineUid = (string) ($lc['line_user_id'] ?? ($mem['line_user_id'] ?? ''));
            if ($lineUid !== '') {
                $ex = db()->prepare('SELECT member_id FROM line_contacts WHERE line_user_id = ?');
                $ex->execute([$lineUid]);
                $found = $ex->fetch();
                if ($found === false) {
                    db()->prepare(
                        'INSERT INTO line_contacts (line_user_id, member_id, display_name, onboarding_state, approved, credentials_sent, created_at, updated_at, hidden)
                         VALUES (?,?,?,?,?,?,?,?,0)'
                    )->execute([
                        $lineUid,
                        $id,
                        (string) ($lc['display_name'] ?? ''),
                        (string) ($lc['onboarding_state'] ?? 'member'),
                        (int) ($lc['approved'] ?? 1),
                        (int) ($lc['credentials_sent'] ?? 1),
                        (int) ($lc['created_at'] ?? time()),
                        time(),
                    ]);
                    $lineNote = '／LINEの友だち紐付けも作りました';
                } elseif (((string) ($found['member_id'] ?? '')) === '') {
                    db()->prepare('UPDATE line_contacts SET member_id = ?, updated_at = ? WHERE line_user_id = ?')
                        ->execute([$id, time(), $lineUid]);
                    $lineNote = '／既にあったLINE友だちに紐付けました';
                } else {
                    $lineNote = '／LINE友だちは既に別の会員に紐付いていたので触っていません';
                }
            }

            db()->commit();
            $done++;
            $out[] = '取り込みました: ' . $loginId . ' / ' . $id
                . '（名前: ' . (((string) ($prof['name_text'] ?? '')) !== '' ? (string) $prof['name_text'] : '未入力')
                . '、画像: ' . implode(',', array_keys(array_filter($paths))) . '、タグ: ' . count($tagIds) . '件'
                . '、リンク: ' . count((array) ($m['links'] ?? [])) . '件）' . $lineNote;
            if ($tagMiss !== []) {
                $out[] = '  ※ 新サイトに無いタグは付けていません: ' . implode(', ', $tagMiss);
            }
            if ($imgNote !== []) {
                $out[] = '  ※ ' . implode('／', $imgNote);
            }
            if ($codeNote !== []) {
                $out[] = '  ※ ' . implode('／', $codeNote);
            }
            audit_log('admin.legacy_import', ['member' => $id, 'login_id' => $loginId, 'tags' => count($tagIds)]);
        } catch (\Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            // 途中で書いた画像は消す（DBは戻っているのにファイルだけ残るのを防ぐ）。
            foreach (['jpg', 'png', 'webp'] as $ext) {
                foreach ([$id, $id . '_cover', $id . '_card'] as $base) {
                    @unlink(dirname(current_db_path()) . '/uploads/' . $base . '.' . $ext);
                }
            }
            $out[] = '失敗しました: ' . $loginId . ' → ' . $e->getMessage();
            $skipped++;
        }
    }

    $out[] = '';
    $out[] = "取り込み {$done} 名 / 飛ばした・失敗 {$skipped} 名";
    if ($done > 0) {
        $out[] = '取り込みが済んだら data/legacy/members.json は消してください（個人情報を置いたままにしないため）。';
    }
    return implode("\n", $out) . "\n";
}
