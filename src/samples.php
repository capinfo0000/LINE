<?php

/**
 * 開発・デモ用のサンプル会員。
 * さがす／プロフィールのUI確認のために、プロフィール・タグ・ポイント付きの会員を投入する。
 * 判別はメール末尾 '@sample.enlink' で行い、管理画面から一括削除できる。
 */

declare(strict_types=1);

/** サンプル会員のメール末尾（この文字列で判別・一括削除する）。 */
function sample_email_suffix(): string
{
    return '@sample.enlink';
}

/** サンプル会員の現在数。 */
function sample_member_count(): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM members WHERE email LIKE ?");
    $stmt->execute(['%' . sample_email_suffix()]);
    return (int) $stmt->fetchColumn();
}

/**
 * サンプル会員を投入する（冪等：同じメールが既にあればスキップ）。
 * @return int 新規作成した人数
 */
function seed_sample_members(): int
{
    // タグ label → id マップ
    $tagId = [];
    foreach (db()->query('SELECT id, category_key, label FROM tags') as $t) {
        $tagId[$t['category_key'] . '|' . $t['label']] = (int) $t['id'];
    }
    $ids = static function (array $keys) use ($tagId): array {
        $out = [];
        foreach ($keys as $k) {
            if (isset($tagId[$k])) {
                $out[] = $tagId[$k];
            }
        }
        return $out;
    };

    $data = [
        ['佐藤 健一', '42', '佐藤テック株式会社 / 代表', 'SaaS開発20年。地方企業のDX支援に注力しています。', 'area|東京', 'job|IT・Web・通信', ['協業', '販路開拓'], ['技術・開発', 'ノウハウ提供'], 6200],
        ['鈴木 美咲', '35', 'ミサキ広告 / プランナー', 'BtoBマーケが得意。良い商品を広めたい方はぜひ。', 'area|大阪', 'job|広告・マーケティング', ['顧客紹介', '協業'], ['販路開拓', 'ノウハウ提供'], 900],
        ['髙橋 亮', '48', '髙橋不動産 / 社長', '収益不動産・事業用地のご相談を。投資家仲間募集中。', 'area|福岡', 'job|不動産・住宅', ['資金・出資', '情報交換'], ['資金・出資', '顧客紹介'], 13500],
        ['田中 由紀', '39', '田中会計事務所 / 代表税理士', '補助金・資金繰りが専門。経営者仲間を探しています。', 'area|東京', 'job|士業', ['顧客紹介', '協業', '情報交換'], ['ノウハウ提供', '資金・出資'], 5200],
        ['渡辺 大輔', '31', '渡辺製作所 / 工場長', '金属加工・小ロット試作が得意。仕入先も探しています。', 'area|愛知', 'job|製造・メーカー', ['仕入・調達', '販路開拓'], ['技術・開発'], 300],
        ['伊藤 彩', '44', 'いとう訪問看護 / 管理者', '医療福祉の現場を良くしたい。人材・連携を募集。', 'area|神奈川', 'job|医療・福祉', ['採用・人材', '仲間づくり'], ['ノウハウ提供'], 1800],
        ['山本 拓也', '37', '山本商店 / 店主', '飲食2店舗経営。販路とコラボ相手を探しています。', 'area|北海道', 'job|飲食・食品', ['販路開拓', '顧客紹介'], ['仕入・調達'], 120],
        ['中村 恵', '52', '中村コンサルティング / 代表', '事業承継・組織づくりの伴走支援。情報交換歓迎。', 'area|京都', 'job|コンサル', ['情報交換', '協業'], ['ノウハウ提供', '採用・人材'], 12800],
        ['小林 直樹', '29', 'コバヤシデザイン / 代表', 'ブランディング・Web制作。スタートアップ支援多数。', 'area|東京', 'job|クリエイティブ', ['顧客紹介', '仲間づくり'], ['技術・開発', 'ノウハウ提供'], 560],
        ['加藤 遥', '41', '加藤人材サービス / 取締役', '採用・人材紹介ならお任せを。良い会社を増やしたい。', 'area|大阪', 'job|教育・研修', ['採用・人材', '協業'], ['採用・人材'], 4300],
        ['吉田 淳', '46', '吉田建設 / 専務', '公共・民間工事。協力会社と資材の調達先を募集。', 'area|広島', 'job|建設・建築・設備', ['仕入・調達', '販路開拓'], ['顧客紹介'], 700],
        ['松本 里奈', '33', 'まつもと薬局 / 薬剤師', '在宅医療に力を入れています。地域の連携相手募集。', 'area|宮城', 'job|医療・福祉', ['仲間づくり', '情報交換'], ['ノウハウ提供'], 200],
    ];

    $created = 0;
    foreach ($data as $i => $s) {
        [$name, $age, $company, $headline, $areaKey, $jobKey, $purposes, $offers, $points] = $s;
        $bio = $headline . "\nまずはお気軽にメッセージください。異業種の方との出会いを楽しみにしています。";
        $email = 'sample' . ($i + 1) . sample_email_suffix();
        if (find_member_by_email($email) !== null) {
            continue; // 冪等
        }
        $cred = issue_member_credentials($email, $name, 'active');
        $mid = $cred['member_id'];

        save_profile($mid, [
            'name_text'     => $name,
            'age_text'      => $age,
            'company_title' => $company,
            'headline'      => $headline,
            'bio'           => $bio,
            'visibility'    => ['directory' => true, 'line_url' => true],
        ]);

        $purposeKeys = array_map(static fn ($p) => 'purpose|' . $p, $purposes);
        $offerKeys   = array_map(static fn ($p) => 'offer|' . $p, $offers);
        set_member_tags($mid, array_merge($ids([$areaKey, $jobKey]), $ids($purposeKeys), $ids($offerKeys)));

        // 求める条件（seek）：提供タグの逆を求める形でざっくり設定（おすすめが出やすいように）。
        save_preferences($mid, $ids([$areaKey]), $ids([$jobKey]), $ids($purposeKeys));

        if ((int) $points > 0) {
            add_points($mid, (int) $points, 'admin_adjust', null, 'sample seed');
        }
        $created++;
    }

    // 既存・新規を問わず、サンプル会員に顔写真を割り当てる（未設定のみ）。
    attach_sample_photos(false);

    return $created;
}

/**
 * サンプル会員に同梱の顔写真（src/sample_photos/sampleN.jpg）を割り当てる。
 * $force=false のときは写真未設定の会員のみ。$force=true は全員上書き。
 * @return int 写真を設定した人数
 */
function attach_sample_photos(bool $force = false): int
{
    $dir = __DIR__ . '/sample_photos';
    $n = 0;
    for ($i = 1; $i <= 12; $i++) {
        $email = 'sample' . $i . sample_email_suffix();
        $m = find_member_by_email($email);
        if ($m === null) {
            continue;
        }
        $mid = (string) $m['id'];
        if (!$force) {
            $prof = get_profile($mid);
            if (($prof['photo_status'] ?? '') === 'approved') {
                continue; // 既に写真あり
            }
        }
        $src = $dir . '/sample' . $i . '.jpg';
        if (!is_file($src)) {
            continue;
        }
        $err = '';
        if (save_member_photo($mid, ['error' => UPLOAD_ERR_OK, 'size' => filesize($src), 'tmp_name' => $src], $err)) {
            $n++;
        }
    }
    return $n;
}

/**
 * サンプル会員を一括削除する（関連データも削除）。
 * @return int 削除した人数
 */
function delete_sample_members(): int
{
    $stmt = db()->prepare('SELECT id FROM members WHERE email LIKE ?');
    $stmt->execute(['%' . sample_email_suffix()]);
    $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
    if ($ids === []) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));

    // member_id 列で紐づくテーブル
    foreach (['profiles', 'member_tags', 'match_preferences', 'member_links', 'point_ledger'] as $tbl) {
        db()->prepare("DELETE FROM {$tbl} WHERE member_id IN ({$ph})")->execute($ids);
    }
    // from/to・rater/target・referrer/joiner のペア列
    $pairs = [
        'member_interests'   => ['from_id', 'to_id'],
        'member_evaluations' => ['rater_id', 'target_id'],
        'referrals'          => ['referrer_id', 'joiner_id'],
    ];
    foreach ($pairs as $tbl => $cols) {
        foreach ($cols as $col) {
            db()->prepare("DELETE FROM {$tbl} WHERE {$col} IN ({$ph})")->execute($ids);
        }
    }
    // LINE連絡先の会員紐付けは残っていれば解除
    db()->prepare("UPDATE line_contacts SET member_id = NULL WHERE member_id IN ({$ph})")->execute($ids);

    db()->prepare("DELETE FROM members WHERE id IN ({$ph})")->execute($ids);
    return count($ids);
}
