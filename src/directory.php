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
 * @return array<int,array> members+profiles の行（visibility.directory=true のみ）
 */
function search_directory(array $filters, string $viewerId, int $limit = 60): array
{
    $where = ["m.status = 'active'", 'm.id != :viewer'];
    $params = [':viewer' => $viewerId];

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

    // 累計獲得ポイント（実績）で上位表示（同点は入会日→作成日の新しい順）。
    // 実績は消費や減点で下がらないため、ポイントを使っても上位表示の順位は保たれる。
    $sql = 'SELECT m.id AS member_id, m.login_id, m.joined_at,
                   p.name_text, p.age_text, p.company_title, p.headline, p.bio, p.photo_status, p.visibility_flags,
                   (SELECT COALESCE(SUM(pl.delta), 0) FROM point_ledger pl WHERE pl.member_id = m.id AND pl.delta > 0) AS points_earned
              FROM members m
              JOIN profiles p ON p.member_id = m.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY points_earned DESC, COALESCE(m.joined_at, 0) DESC, m.created_at DESC
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
