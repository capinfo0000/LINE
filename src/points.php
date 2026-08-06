<?php

/**
 * ポイント制度（台帳方式）。
 * - point_ledger に増減を1行ずつ記録し、残高は合計で求める（履歴が残る）。
 * - 貯め方: 紹介入会（紹介者/入会者）、他会員からの評価(good)。
 * - 減り方: 運営が通報を確認して減算、運営の手動調整。
 * - 称号は残高から自動判定（しきい値表）。
 * ※アイテム購入・提携店クーポンは今回スコープ外（後続）。
 */

declare(strict_types=1);

/** 付与量（.env で上書き可）。 */
function points_amount(string $key): int
{
    $defaults = ['referrer' => 100, 'joiner' => 50, 'praise' => 10, 'report_penalty' => 20, 'referral_monthly' => 50];
    $env = env('POINTS_' . strtoupper($key));
    if ($env !== null && $env !== '' && is_numeric($env)) {
        return (int) $env;
    }
    return $defaults[$key] ?? 0;
}

/**
 * 称号ラダー（しきい値昇順）。称号はポイント残高で決まる。
 * 紹介1人＝紹介者に100pt なので、しきい値は「紹介 5/50/100人 相当」に対応する
 * 500/5,000/10,000pt に設定（評価や継続ボーナス等の全ポイント活動も反映）。
 */
function points_title_ladder(): array
{
    return [
        ['min' => 0,      'label' => 'ルーキー'],
        ['min' => 500,    'label' => 'レギュラー'],
        ['min' => 5000,   'label' => 'ゴールド'],
        ['min' => 10000,  'label' => 'プラチナ'],
    ];
}

/** 残高から称号ラベルを返す。 */
function points_title(int $balance): string
{
    $title = 'ルーキー';
    foreach (points_title_ladder() as $t) {
        if ($balance >= $t['min']) {
            $title = $t['label'];
        }
    }
    return $title;
}

/**
 * ポイントを増減して台帳に記録する。
 *
 * @param string      $memberId     対象会員
 * @param int         $delta        増減（負も可）
 * @param string      $reason       理由コード（referral_referrer/referral_joiner/praise/report_penalty/admin_adjust 等）
 * @param string|null $refMemberId  関連会員（紹介者/評価者など・任意）
 * @param string      $note         メモ（任意）
 */
function add_points(string $memberId, int $delta, string $reason, ?string $refMemberId = null, string $note = ''): void
{
    if ($delta === 0) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO point_ledger (member_id, delta, reason, ref_member_id, note, created_at)
         VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([$memberId, $delta, $reason, $refMemberId, mb_substr($note, 0, 200), time()]);
}

/** 会員の残高（使えるポイント＝台帳の合計。消費や減点で下がる）。 */
function member_points(string $memberId): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(delta),0) FROM point_ledger WHERE member_id = ?');
    $stmt->execute([$memberId]);
    return (int) $stmt->fetchColumn();
}

/**
 * 会員の累計獲得ポイント（プラスの増加のみの合計）。
 * 消費や減点では減らない＝称号（ランク）の判定に使う。一度上がった称号は下がらない。
 */
function member_points_earned(string $memberId): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(delta),0) FROM point_ledger WHERE member_id = ? AND delta > 0');
    $stmt->execute([$memberId]);
    return (int) $stmt->fetchColumn();
}

/** 会員のポイント履歴（新しい順）。 */
function member_point_history(string $memberId, int $limit = 50): array
{
    $stmt = db()->prepare('SELECT * FROM point_ledger WHERE member_id = ? ORDER BY id DESC LIMIT ?');
    $stmt->execute([$memberId, $limit]);
    return $stmt->fetchAll();
}

/** 理由コードの表示ラベル。 */
function point_reason_label(string $reason): string
{
    return [
        'referral_referrer' => '紹介ボーナス（あなたの紹介で入会）',
        'referral_joiner'   => '紹介コード入力ボーナス',
        'referral_monthly'  => '紹介継続ボーナス（毎月の課金継続）',
        'praise'            => '他の会員からの評価',
        'report_penalty'    => '通報による減点',
        'admin_adjust'      => '運営による調整',
    ][$reason] ?? $reason;
}

/* ------------------------- 紹介 ------------------------- */

/** 紹介専用コードから会員を引く。無ければ null。大文字小文字は無視。 */
function find_member_by_referral_code(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM members WHERE referral_code = ? LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 会員が既に紹介者を登録済みか。 */
function has_referrer(string $joinerId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM referrals WHERE joiner_id = ? LIMIT 1');
    $stmt->execute([$joinerId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * 紹介者コードを登録し、紹介者・入会者の双方にポイントを付与する（1回限り）。
 *
 * @return array{ok:bool, message:string}
 */
function record_referral(string $joinerId, string $referrerCode): array
{
    if (has_referrer($joinerId)) {
        return ['ok' => false, 'message' => 'すでに紹介者を登録済みです（変更はできません）。'];
    }
    $referrer = find_member_by_referral_code($referrerCode);
    if ($referrer === null) {
        return ['ok' => false, 'message' => '紹介コードが見つかりません。コードを正しく入力してください。'];
    }
    if ((string) $referrer['id'] === $joinerId) {
        return ['ok' => false, 'message' => '自分のコードは登録できません。'];
    }
    if (($referrer['status'] ?? '') !== 'active') {
        return ['ok' => false, 'message' => 'この紹介コードは現在利用できません。'];
    }

    // 原子的に紐付け（重複は UNIQUE で弾く）。
    try {
        db()->prepare('INSERT INTO referrals (referrer_id, joiner_id, created_at) VALUES (?,?,?)')
            ->execute([$referrer['id'], $joinerId, time()]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => 'すでに紹介者を登録済みです。'];
    }

    add_points((string) $referrer['id'], points_amount('referrer'), 'referral_referrer', $joinerId);
    add_points($joinerId, points_amount('joiner'), 'referral_joiner', (string) $referrer['id']);

    return ['ok' => true, 'message' => '紹介者を登録しました。あなたに ' . points_amount('joiner') . 'pt、紹介者に ' . points_amount('referrer') . 'pt を付与しました。'];
}

/** 会員が紹介した人数。 */
function referral_count(string $referrerId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_id = ?');
    $stmt->execute([$referrerId]);
    return (int) $stmt->fetchColumn();
}

/* ------------------------- 評価 / 通報 ------------------------- */

/** rater が target を kind で評価済みか。 */
function has_evaluated(string $raterId, string $targetId, string $kind): bool
{
    $stmt = db()->prepare('SELECT 1 FROM member_evaluations WHERE rater_id = ? AND target_id = ? AND kind = ? LIMIT 1');
    $stmt->execute([$raterId, $targetId, $kind]);
    return (bool) $stmt->fetchColumn();
}

/**
 * 会員間の評価(praise) / 通報(report)。
 * - praise: 同一相手に1回のみ。相手に加点。
 * - report: 同一相手に1回のみ。自動減点はせず運営レビュー待ちにする。
 *
 * @return array{ok:bool, message:string}
 */
function evaluate_member(string $raterId, string $targetId, string $kind, string $note = ''): array
{
    if (!in_array($kind, ['praise', 'report'], true)) {
        return ['ok' => false, 'message' => '不正な操作です。'];
    }
    if ($raterId === $targetId) {
        return ['ok' => false, 'message' => '自分自身は評価できません。'];
    }
    if (has_evaluated($raterId, $targetId, $kind)) {
        return ['ok' => false, 'message' => $kind === 'praise' ? 'この会員は評価済みです。' : 'この会員は通報済みです。'];
    }
    // 承認フローは廃止。評価も通報もユーザー操作で即時にポイントへ反映する（handled=1）。
    try {
        db()->prepare('INSERT INTO member_evaluations (rater_id, target_id, kind, note, handled, created_at) VALUES (?,?,?,?,1,?)')
            ->execute([$raterId, $targetId, $kind, mb_substr($note, 0, 300), time()]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => 'すでに登録済みです。'];
    }
    if ($kind === 'praise') {
        add_points($targetId, points_amount('praise'), 'praise', $raterId);
        return ['ok' => true, 'message' => '評価しました。相手に ' . points_amount('praise') . 'pt を付与しました。'];
    }
    // 通報：即時に減点（1人1回なので、多人数の通報ほど下がる）。
    add_points($targetId, -abs(points_amount('report_penalty')), 'report_penalty', $raterId);
    return ['ok' => true, 'message' => '通報を受け付けました。ご協力ありがとうございます。'];
}

/** 会員が受けた評価(praise)数。 */
function praise_count(string $memberId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM member_evaluations WHERE target_id = ? AND kind = 'praise'");
    $stmt->execute([$memberId]);
    return (int) $stmt->fetchColumn();
}

/** 会員が受けた通報(report)数（運営の濫用検知・オーバービュー用）。 */
function report_count(string $memberId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM member_evaluations WHERE target_id = ? AND kind = 'report'");
    $stmt->execute([$memberId]);
    return (int) $stmt->fetchColumn();
}

/* ------------------------- 運営（通報レビュー） ------------------------- */

/** 未処理の通報一覧（新しい順）。 */
function pending_reports(int $limit = 100): array
{
    $stmt = db()->prepare(
        "SELECT e.id, e.rater_id, e.target_id, e.note, e.created_at,
                rt.login_id AS target_login, tg.login_id AS rater_login
           FROM member_evaluations e
           LEFT JOIN members rt ON rt.id = e.target_id
           LEFT JOIN members tg ON tg.id = e.rater_id
          WHERE e.kind = 'report' AND e.handled = 0
          ORDER BY e.id DESC LIMIT ?"
    );
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/** 未処理の通報件数（バッジ表示用）。 */
function pending_report_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM member_evaluations WHERE kind = 'report' AND handled = 0")->fetchColumn();
}

/**
 * 通報を処理する。$penalty > 0 なら対象会員を減点する。
 */
function resolve_report(int $reportId, int $penalty = 0): void
{
    $stmt = db()->prepare("SELECT * FROM member_evaluations WHERE id = ? AND kind = 'report'");
    $stmt->execute([$reportId]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['handled'] === 1) {
        return;
    }
    if ($penalty > 0) {
        add_points((string) $row['target_id'], -abs($penalty), 'report_penalty', (string) $row['rater_id']);
    }
    db()->prepare('UPDATE member_evaluations SET handled = 1 WHERE id = ?')->execute([$reportId]);
}
