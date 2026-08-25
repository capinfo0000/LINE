<?php

/**
 * 意見箱。会員から運営への意見・要望・不具合報告を受け取る。
 *
 * 運営側は一覧で読み、対応済みの印を付けられる。CSVで書き出せる。
 * 会員には「誰から送られたか運営に分かる」ことを画面で明示している
 * （匿名だと思って書かれると困るため。実装と説明を食い違わせない）。
 */

declare(strict_types=1);

/** 本文の上限（文字数）。 */
const FEEDBACK_MAX_LEN = 2000;

/**
 * 種別。キーをDBに入れ、表示はラベルを使う。
 * 分類を絞りすぎると「その他」だらけになるので、運営が動ける粒度にしている。
 *
 * @return array<string,string>
 */
function feedback_kinds(): array
{
    return [
        'improve' => '使いにくいところ・改善してほしいこと',
        'bug'     => '不具合の報告',
        'feature' => '追加してほしい機能',
        'price'   => '料金について',
        'trouble' => '他の会員とのトラブル・困っていること',
        'other'   => 'その他',
    ];
}

/** 種別のラベル（不明なキーはそのまま返す）。 */
function feedback_kind_label(string $kind): string
{
    return feedback_kinds()[$kind] ?? $kind;
}

/**
 * 意見を保存する。
 *
 * @return array{ok:bool, message:string}
 */
function feedback_save(string $memberId, array $in): array
{
    $kind = (string) ($in['kind'] ?? '');
    $body = clean_multiline_text((string) ($in['body'] ?? ''));

    if (!isset(feedback_kinds()[$kind])) {
        return ['ok' => false, 'message' => '種別を選んでください。'];
    }
    if ($body === '') {
        return ['ok' => false, 'message' => '内容が未入力です。お気づきの点をご記入ください。'];
    }
    if (mb_strlen($body) > FEEDBACK_MAX_LEN) {
        return ['ok' => false, 'message' => '内容が長すぎます。' . number_format(FEEDBACK_MAX_LEN) . '文字以内でご記入ください。'];
    }
    // 連投の抑止。会員単位で1時間に5件まで。
    if (!rate_limit_check('feedback', 5, 3600, 'm:' . $memberId)) {
        return ['ok' => false, 'message' => '短時間に多く送信されています。しばらく時間をおいてからお試しください。'];
    }

    $stmt = db()->prepare(
        'INSERT INTO feedbacks (member_id, kind, body, handled, created_at) VALUES (?,?,?,0,?)'
    );
    $stmt->execute([$memberId, $kind, $body, time()]);
    audit_log('member.feedback_sent', ['kind' => $kind, 'len' => mb_strlen($body)]);

    return ['ok' => true, 'message' => 'ご意見をお送りしました。ありがとうございます。'];
}

/**
 * 運営向けの一覧。
 *
 * @param string $filter 'all' | 'open'（未対応のみ） | 'done'（対応済みのみ）
 */
function feedback_list(string $filter = 'all', int $limit = 300): array
{
    $where = '';
    if ($filter === 'open') {
        $where = 'WHERE f.handled = 0';
    } elseif ($filter === 'done') {
        $where = 'WHERE f.handled = 1';
    }
    $sql = "SELECT f.*, m.login_id, p.name_text
              FROM feedbacks f
              LEFT JOIN members m ON m.id = f.member_id
              LEFT JOIN profiles p ON p.member_id = f.member_id
              {$where}
             ORDER BY f.handled, f.id DESC
             LIMIT " . max(1, $limit);
    return db()->query($sql)->fetchAll() ?: [];
}

/** 未対応の件数（運営メニューのバッジ用）。 */
function feedback_open_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM feedbacks WHERE handled = 0')->fetchColumn();
}

/** 対応済み／未対応を切り替える。存在しなければ false。 */
function feedback_set_handled(int $id, bool $handled): bool
{
    $stmt = db()->prepare('UPDATE feedbacks SET handled = ?, handled_at = ? WHERE id = ?');
    $stmt->execute([$handled ? 1 : 0, $handled ? time() : null, $id]);
    if ($stmt->rowCount() > 0) {
        audit_log('admin.feedback_handled', ['id' => $id, 'handled' => $handled ? 1 : 0]);
        return true;
    }
    return false;
}

/** 削除。 */
function feedback_delete(int $id): bool
{
    $stmt = db()->prepare('DELETE FROM feedbacks WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() > 0) {
        audit_log('admin.feedback_deleted', ['id' => $id]);
        return true;
    }
    return false;
}

/**
 * CSV を組み立てて返す（ダウンロード用）。
 *
 * Excel で開けるように UTF-8 の BOM を先頭に付ける（無いと日本語が化ける）。
 * 値は csv_cell() を通す。'=' や '+' で始まる文字列がそのまま数式として
 * 実行されるのを防ぐため（CSVインジェクション対策）。
 */
function feedback_csv(string $filter = 'all'): string
{
    $rows = feedback_list($filter, 100000);
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        return '';
    }
    fputcsv($fh, ['ID', '受信日時', '状態', '種別', 'ログインID', '名前', '内容', '対応日時']);
    foreach ($rows as $r) {
        fputcsv($fh, [
            (string) $r['id'],
            date('Y-m-d H:i', (int) $r['created_at']),
            (int) $r['handled'] === 1 ? '対応済み' : '未対応',
            csv_cell(feedback_kind_label((string) $r['kind'])),
            csv_cell((string) ($r['login_id'] ?? '（退会済み）')),
            csv_cell((string) ($r['name_text'] ?? '')),
            csv_cell((string) $r['body']),
            $r['handled_at'] ? date('Y-m-d H:i', (int) $r['handled_at']) : '',
        ]);
    }
    rewind($fh);
    $csv = (string) stream_get_contents($fh);
    fclose($fh);
    return "\xEF\xBB\xBF" . $csv;
}
