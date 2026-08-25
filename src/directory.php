<?php

/**
 * 会員ディレクトリ（有効会員限定）と条件検索。
 *
 * 検索は一方向フィルタ（探索用）：指定した軸（場所/仕事/目的タグ・キーワード）で絞り込む。
 * 双方向マッチのおすすめは別ロジック（Phase 6）。
 */

declare(strict_types=1);

/**
 * ディレクトリを検索する。
 *
 * @param array{area?:int[],job?:int[],purpose?:int[],keyword?:string} $filters
 * @param string $viewerId 除外する自分のID
 * @param int    $limit    取得件数上限
 * @param string $order    並び順：'points'（実績上位・既定）/ 'new'（新着）
 * @param int[]|null $onlyIds 指定時はこの会員IDのみに絞る（気になる一覧など）
 * @return array<int,array> members+profiles の行（visibility.directory=true のみ）
 */
function search_directory(array $filters, string $viewerId, int $limit = 60, string $order = 'points', ?array $onlyIds = null): array
{
    $where = ["m.status = 'active'", 'm.id != :viewer'];
    $params = [':viewer' => $viewerId];

    // 対象IDの限定（気になる一覧など）。空配列なら結果ゼロ。
    if ($onlyIds !== null) {
        $onlyIds = array_values(array_unique(array_filter(array_map('strval', $onlyIds), static fn ($v) => $v !== '')));
        if ($onlyIds === []) {
            return [];
        }
        $ph = [];
        foreach ($onlyIds as $i => $id) {
            $key = ":only{$i}";
            $ph[] = $key;
            $params[$key] = $id;
        }
        $where[] = 'm.id IN (' . implode(',', $ph) . ')';
    }

    // タグ軸フィルタ（軸内は OR、軸間は AND）。有効タグIDのみ採用。
    foreach (['area', 'job', 'purpose'] as $axis) {
        $ids = array_values(array_unique(array_map('intval', $filters[$axis] ?? [])));
        $ids = array_values(array_intersect($ids, valid_tag_ids($axis)));
        if ($ids === []) {
            continue;
        }
        $ph = [];
        foreach ($ids as $i => $id) {
            $key = ":{$axis}{$i}";
            $ph[] = $key;
            $params[$key] = $id;
        }
        $where[] = 'EXISTS (SELECT 1 FROM member_tags mt WHERE mt.member_id = m.id AND mt.tag_id IN (' . implode(',', $ph) . '))';
    }

    // キーワード（名前・見出し・自己紹介・会社/肩書き）
    $keyword = trim((string) ($filters['keyword'] ?? ''));
    if ($keyword !== '') {
        $params[':kw'] = '%' . $keyword . '%';
        $where[] = '(p.name_text LIKE :kw OR p.headline LIKE :kw OR p.bio LIKE :kw OR p.company_title LIKE :kw)';
    }

    // 並び順：新着＝入会日→作成日の新しい順／既定＝累計獲得ポイント（実績）上位。
    // 実績は消費や減点で下がらないため、ポイントを使っても上位表示の順位は保たれる。
    $orderBy = $order === 'new'
        ? 'COALESCE(m.joined_at, m.created_at) DESC, m.created_at DESC'
        : "(m.plan = 'premium') DESC, points_earned DESC, COALESCE(m.joined_at, 0) DESC, m.created_at DESC";
    $sql = 'SELECT m.id AS member_id, m.login_id, m.joined_at,
                   p.name_text, p.age_text, p.birthdate, p.company_title, p.headline, p.bio, p.photo_status, p.photo_path, p.visibility_flags,
                   (SELECT COALESCE(SUM(pl.delta), 0) FROM point_ledger pl WHERE pl.member_id = m.id AND pl.delta > 0) AS points_earned
              FROM members m
              JOIN profiles p ON p.member_id = m.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $orderBy . '
             LIMIT ' . (int) $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // visibility.directory=false は除外（既定 true）。
    $out = [];
    foreach ($rows as $r) {
        $vis = profile_visibility($r);
        if (!empty($vis['directory'])) {
            $out[] = $r;
        }
    }
    return $out;
}

/**
 * 閲覧者が「気になる」を付けた相手の会員IDを新しい順で返す。
 *
 * @return string[]
 */
function liked_member_ids(string $viewerId): array
{
    $stmt = db()->prepare('SELECT to_id FROM member_interests WHERE from_id = ? ORDER BY created_at DESC');
    $stmt->execute([$viewerId]);
    return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
}

/**
 * 足あとを記録する（$fromId が $toId のプロフィールを閲覧）。
 * 訪問者ごとに最終閲覧時刻を1件だけ保持（同一相手はUPDATEで更新）。自分自身は記録しない。
 */
function record_member_view(string $fromId, string $toId): void
{
    if ($fromId === '' || $toId === '' || $fromId === $toId) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO member_views (from_id, to_id, created_at) VALUES (?, ?, ?)
         ON CONFLICT(from_id, to_id) DO UPDATE SET created_at = excluded.created_at'
    );
    $stmt->execute([$fromId, $toId, time()]);
}

/**
 * 自分のプロフィールを見に来た相手（足あと）の会員IDを新しい順で返す。
 *
 * @return string[]
 */
function footprint_visitor_ids(string $memberId): array
{
    $stmt = db()->prepare('SELECT from_id FROM member_views WHERE to_id = ? ORDER BY created_at DESC');
    $stmt->execute([$memberId]);
    return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
}

/**
 * 自分への足あと件数（訪問者の実人数）。
 */
function footprint_count(string $memberId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM member_views WHERE to_id = ?');
    $stmt->execute([$memberId]);
    return (int) $stmt->fetchColumn();
}

/**
 * 会員のタグをカテゴリ別ラベルで返す [category_key => [labels]]。
 */
function member_tag_labels(string $memberId): array
{
    $stmt = db()->prepare(
        'SELECT t.category_key, t.label
           FROM member_tags mt JOIN tags t ON t.id = mt.tag_id
          WHERE mt.member_id = ?
          ORDER BY t.category_key, t.sort'
    );
    $stmt->execute([$memberId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['category_key']][] = $r['label'];
    }
    return $out;
}

/**
 * ディレクトリ/詳細で表示するリンクを、閲覧者の可視制約を考慮して返す。
 * line_add リンクは所有者の visibility.line_url が true のときのみ含める。
 *
 * @return array<int,array>
 */
function visible_member_links(string $memberId, array $ownerProfile): array
{
    $vis = profile_visibility($ownerProfile);
    $links = get_member_links($memberId);
    $out = [];
    foreach ($links as $l) {
        if (($l['kind'] ?? '') === 'line_add' && empty($vis['line_url'])) {
            continue;
        }
        $out[] = $l;
    }
    return $out;
}

/**
 * 詳細表示できる会員か（有効・プロフィールあり・ディレクトリ掲載）を判定して行を返す。
 * 不可なら null。
 */
function viewable_member_profile(string $memberId): ?array
{
    $member = find_member_by_id($memberId);
    if ($member === null || !member_can_login($member)) {
        return null;
    }
    $profile = get_profile($memberId);
    // プロフィール未作成（updated_at null かつ本文空）は非表示。
    if (($profile['updated_at'] ?? null) === null) {
        return null;
    }
    if (empty(profile_visibility($profile)['directory'])) {
        return null;
    }
    return ['member' => $member, 'profile' => $profile];
}
