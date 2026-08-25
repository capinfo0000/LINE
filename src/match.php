<?php

/**
 * 双方向マッチング（おすすめ）。
 *
 * おすすめは「A の求める条件に B が合致」かつ「B の求める条件に A が合致」した相手だけ。
 * 片方向だけの一致はおすすめに出さない（検索には出る）。
 *
 * ゲート（両立必須）:
 *   dir(seeker→target) = 指定軸ごとに AND、未指定軸はワイルドカード。
 *     (seek_area 空 or target.area ∩ seek_area) AND (seek_job 〃) AND (seek_purpose 〃)
 *
 * スコア（大きいほど上位）:
 *   - 価値の相補性（最重視）: |A.purpose ∩ B.offer| + |B.purpose ∩ A.offer|   … 求めること↔提供できること
 *   - 軸の一致（場所/仕事/目的）の重なり数
 */

declare(strict_types=1);

const MATCH_W_VALUE = 10; // 価値相補性の重み（最重視）
const MATCH_W_AXIS  = 2;  // 軸一致の重み

/** 会員の属性タグ集合（カテゴリ別 tag_id 配列）。 */
function member_attributes(string $memberId): array
{
    $stmt = db()->prepare(
        'SELECT t.category_key AS c, t.id AS id
           FROM member_tags mt JOIN tags t ON t.id = mt.tag_id
          WHERE mt.member_id = ?'
    );
    $stmt->execute([$memberId]);
    $out = ['area' => [], 'job' => [], 'purpose' => [], 'offer' => []];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['c']][] = (int) $r['id'];
    }
    return $out;
}

/**
 * 「あなたへのおすすめ」の候補を返す。
 *
 * 条件は単純で、相手の「提供できること」が自分の「求めていること」と
 * 1つ以上重なっていること。重なりが無い相手は出さない。
 * さらに、自分の「提供できること」が相手の「求めていること」とも重なる
 * （＝お互いに噛み合う）相手を上位に出す。
 *
 * ※「求めていること」と「提供できること」は同じ語でも別のタグ行（別ID）なので、
 *   ID ではなくラベルで突き合わせる。
 *
 * 並び：相互マッチ → 重なりの多さ → 実績ポイント（search_directory 側の既定）
 *
 * @return array<int,array<string,mixed>> search_directory と同じ形の行
 */
function recommend_offer_matches(string $viewerId, int $limit = 10): array
{
    $myPurpose = member_tag_labels_of($viewerId, 'purpose'); // 自分が求めていること
    $myOffer   = member_tag_labels_of($viewerId, 'offer');   // 自分が提供できること
    if ($myPurpose === []) {
        return []; // 求めていることが未設定なら、おすすめのしようがない
    }

    // 相手の offer ラベルが自分の purpose ラベルに重なる件数を数える。
    $hits = members_with_tag_labels('offer', $myPurpose, $viewerId);
    if ($hits === []) {
        return [];
    }
    // 相互マッチ（相手の purpose が自分の offer に重なる相手）。
    $mutual = $myOffer === [] ? [] : members_with_tag_labels('purpose', $myOffer, $viewerId);

    // 表示可否（有効会員・ディレクトリ掲載）は search_directory に任せる。
    $rows = search_directory([], $viewerId, max(60, count($hits)), 'points', array_keys($hits));
    usort($rows, static function (array $a, array $b) use ($hits, $mutual): int {
        $ida = (string) $a['member_id'];
        $idb = (string) $b['member_id'];
        $ma = isset($mutual[$ida]) ? 1 : 0;
        $mb = isset($mutual[$idb]) ? 1 : 0;
        if ($ma !== $mb) {
            return $mb <=> $ma;
        }
        return ($hits[$idb] ?? 0) <=> ($hits[$ida] ?? 0);
    });
    return array_slice($rows, 0, $limit);
}

/**
 * 会員が持つ、指定カテゴリのタグ「ラベル」一覧。
 *
 * @return string[]
 */
function member_tag_labels_of(string $memberId, string $category): array
{
    $stmt = db()->prepare(
        'SELECT t.label FROM member_tags mt JOIN tags t ON t.id = mt.tag_id
          WHERE mt.member_id = ? AND t.category_key = ?'
    );
    $stmt->execute([$memberId, $category]);
    return array_values(array_unique(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [])));
}

/**
 * 指定カテゴリで、与えたラベル群のいずれかを持つ会員と、その一致数。
 *
 * @param string[] $labels
 * @return array<string,int> member_id => 一致数
 */
function members_with_tag_labels(string $category, array $labels, string $excludeMemberId): array
{
    if ($labels === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($labels), '?'));
    $stmt = db()->prepare(
        "SELECT mt.member_id AS id, COUNT(DISTINCT t.label) AS hits
           FROM member_tags mt JOIN tags t ON t.id = mt.tag_id
          WHERE t.category_key = ? AND t.label IN ({$ph}) AND mt.member_id <> ?
          GROUP BY mt.member_id"
    );
    $stmt->execute(array_merge([$category], $labels, [$excludeMemberId]));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(string) $r['id']] = (int) $r['hits'];
    }
    return $out;
}

/** seeker→target の片方向成立判定と、一致した軸数を返す。 */
function match_direction(array $seek, array $targetAttrs): array
{
    $matchedAxes = 0;
    foreach (['area', 'job', 'purpose'] as $axis) {
        $want = $seek['seek_' . $axis] ?? [];
        if ($want === []) {
            continue; // 未指定＝ワイルドカード（通過・加点なし）
        }
        $have = $targetAttrs[$axis] ?? [];
        $overlap = array_intersect($want, $have);
        if ($overlap === []) {
            return ['ok' => false, 'axes' => 0]; // 指定軸で不一致 → 不成立
        }
        $matchedAxes++;
    }
    return ['ok' => true, 'axes' => $matchedAxes];
}

/**
 * A と B の双方向マッチを評価する。成立なら score と理由、非成立なら null。
 *
 * @return array{score:int, reasons:array<int,string>}|null
 */
function evaluate_pair(array $aAttrs, array $aSeek, array $bAttrs, array $bSeek, array $labelMap): ?array
{
    $ab = match_direction($aSeek, $bAttrs);
    if (!$ab['ok']) {
        return null;
    }
    $ba = match_direction($bSeek, $aAttrs);
    if (!$ba['ok']) {
        return null;
    }

    // 価値の相補性：A の目的(求めること) ↔ B の提供できること／その逆。
    // purpose と offer はカテゴリが別＝同一ラベルでも tag_id が異なるため、ラベルで突き合わせる。
    $toLabels = static function (array $ids) use ($labelMap): array {
        $out = [];
        foreach ($ids as $id) {
            $lb = $labelMap[$id] ?? '';
            if ($lb !== '') {
                $out[] = $lb;
            }
        }
        return $out;
    };
    $aWantsBoffers = array_values(array_intersect($toLabels($aAttrs['purpose']), $toLabels($bAttrs['offer'])));
    $bWantsAoffers = array_values(array_intersect($toLabels($bAttrs['purpose']), $toLabels($aAttrs['offer'])));
    $valueCount = count($aWantsBoffers) + count($bWantsAoffers);

    $axisCount = $ab['axes'] + $ba['axes'];
    $score = $valueCount * MATCH_W_VALUE + $axisCount * MATCH_W_AXIS;

    $reasons = [];
    foreach ($aWantsBoffers as $lb) {
        $reasons[] = "あなたが求める「{$lb}」を提供できる方です";
    }
    foreach ($bWantsAoffers as $lb) {
        $reasons[] = "あなたが提供できる「{$lb}」を求めている方です";
    }
    if ($reasons === []) {
        $reasons[] = 'お互いの希望条件が合致しています';
    }

    return ['score' => $score, 'reasons' => array_slice(array_values(array_unique($reasons)), 0, 4)];
}

/** tag_id → label の対応表。 */
function tag_label_map(): array
{
    $rows = db()->query('SELECT id, label FROM tags')->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['id']] = $r['label'];
    }
    return $map;
}

/**
 * 指定会員への双方向おすすめを計算する（ライブ）。
 *
 * @return array<int,array{member_id:string,name:string,headline:string,company_title:string,
 *                         age_text:string,birthdate:string,photo_status:string,photo_path:string,score:int,reasons:array<int,string>}>
 */
function compute_recommendations_for(string $memberId, int $limit = 20): array
{
    $viewer = find_member_by_id($memberId);
    if ($viewer === null || !member_can_login($viewer)) {
        return [];
    }
    $aAttrs = member_attributes($memberId);
    $aSeek = get_preferences($memberId);
    $labelMap = tag_label_map();

    // 候補：有効・プロフィールあり・ディレクトリ掲載・自分以外。
    $stmt = db()->prepare(
        "SELECT m.id AS member_id, p.name_text, p.age_text, p.birthdate, p.company_title, p.headline, p.photo_status, p.photo_path, p.visibility_flags
           FROM members m JOIN profiles p ON p.member_id = m.id
          WHERE m.status = 'active' AND m.id != ?"
    );
    $stmt->execute([$memberId]);
    $candidates = $stmt->fetchAll();

    $scored = [];
    foreach ($candidates as $cand) {
        if (empty(profile_visibility($cand)['directory'])) {
            continue;
        }
        $bid = $cand['member_id'];
        $bAttrs = member_attributes($bid);
        $bSeek = get_preferences($bid);
        $eval = evaluate_pair($aAttrs, $aSeek, $bAttrs, $bSeek, $labelMap);
        if ($eval === null) {
            continue;
        }
        $scored[] = [
            'member_id'     => $bid,
            'name'          => (string) $cand['name_text'],
            'headline'      => (string) $cand['headline'],
            'company_title' => (string) $cand['company_title'],
            'age_text'      => (string) $cand['age_text'],
            'birthdate'     => (string) ($cand['birthdate'] ?? ''),
            'photo_status'  => (string) $cand['photo_status'],
            'photo_path'    => (string) ($cand['photo_path'] ?? ''),
            'score'         => $eval['score'],
            'reasons'       => $eval['reasons'],
        ];
    }

    usort($scored, static fn ($x, $y) => $y['score'] <=> $x['score']);
    return array_slice($scored, 0, $limit);
}

/**
 * 週次バッチ：全有効会員のおすすめを計算して recommendations に保存する。
 * 表示はライブ計算を使うが、集計・分析用に永続化する。
 */
function rebuild_all_recommendations(): int
{
    $batchId = 'batch_' . bin2hex(random_bytes(5));
    $members = db()->query("SELECT id FROM members WHERE status = 'active'")->fetchAll();
    $total = 0;
    $ins = db()->prepare(
        'INSERT OR IGNORE INTO recommendations (batch_id, member_id, recommended_member_id, score, reason_json, created_at)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ($members as $m) {
        $recs = compute_recommendations_for($m['id'], 50);
        foreach ($recs as $rank => $r) {
            $ins->execute([$batchId, $m['id'], $r['member_id'], $r['score'], json_encode($r['reasons'], JSON_UNESCAPED_UNICODE), time()]);
            $total++;
        }
    }
    // 古いバッチを掃除（最新バッチのみ保持）。
    $del = db()->prepare('DELETE FROM recommendations WHERE batch_id != ?');
    $del->execute([$batchId]);
    return $total;
}
