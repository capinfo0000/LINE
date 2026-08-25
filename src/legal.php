<?php

/**
 * 規約・ポリシーの本文を管理画面から編集できるようにする仕組み。
 *
 * 本文は app_settings（legal_* キー）にプレーンテキストで保存する。未保存なら
 * ここに書いた既定文を使う。本文中の {事業者名} {入会の流れ} などの差し込み語は
 * 表示時に置き換わるため、運用モードのON/OFFに連動する部分は編集しても壊れない。
 *
 * 記法（HTMLは書けない。入力はすべてエスケープしてから組み立てる）：
 *   # 見出し          → 見出し（<h2>）
 *   - 項目            → 箇条書き
 *   1. 項目           → 番号付きリスト
 *   [表示文字](URL)   → リンク
 *   **強調**          → 太字
 *   {条}              → 第1条・第2条…（表示される見出しの順に自動採番）
 *   空行              → 段落の区切り
 */

declare(strict_types=1);

/**
 * 編集できる文書の定義。
 *
 * @return array<string,array{label:string,page:string,default:string}>
 */
function legal_doc_defs(): array
{
    return [
        'terms' => [
            'label'   => '利用規約',
            'page'    => '/terms',
            'default' => <<<'TXT'
本規約は、{事業者名}（以下「当方」）が提供する会員制人脈マッチングサービス「{サービス名}」（以下「本サービス」）の利用条件を定めるものです。会員（以下「利用者」）は、本規約に同意のうえ本サービスを利用するものとします。

# {条}（本サービス）
本サービスは、会員がプロフィールを登録し、会員相互の人脈づくり・マッチングを行うための会員専用サイトおよび公式LINEを通じた案内を提供します。決済は決済代行サービス（Stripe）を通じて行われます。

# {条}（入会・会員資格）
1. 本サービスは満{最低年齢}歳以上の方に限りご利用いただけます。
2. {入会の流れ}
3. 会員資格の維持には、当方が定める場合に月額会費（サブスクリプション）のお支払いが必要です。
4. 当方は、必要と判断した場合、入会をお断りし、または会員資格を停止・取り消すことがあります。

# {条}（アカウント）
1. 発行したログインID・パスワードの管理責任は利用者が負い、第三者に利用させてはなりません。初回ログイン時にはパスワードの変更をお願いします。
2. 登録する情報は正確かつ最新に保つものとします。

# {条}（会員情報・連絡先の取り扱い）
1. 会員が登録したプロフィール・タグ・リンク（LINE追加URL等）は、本サービス内で他の有効会員に表示されます。表示させたくない情報は、会員自身がプロフィール編集から削除できます。
2. 会員は、本サービスを通じて知り得た他の会員の情報を、本サービスの目的（人脈づくり）以外に利用し、または第三者へ提供してはなりません。

# {条}（ご利用の条件）
{自己紹介ロック}

# {条}（禁止事項）
法令違反、公序良俗に反する行為、虚偽情報の登録、勧誘・宗教・マルチ商法等の迷惑行為、第三者の権利侵害、他の会員への迷惑・ハラスメント、不正アクセス等を禁止します。

# {条}（料金・返金）
1. {料金}
2. ご紹介の特典条件（当方が定めるアクティブな紹介人数）を満たす場合、月額会費が無料となることがあります。条件を満たさなくなった場合は通常額に復帰します。
3. 会員はいつでも解約でき、解約後は次回以降の請求が停止します。お支払い済みの月額会費の返金は、[キャンセル・返金ポリシー](/policy)に定める場合を除き、原則としてお受けできません（当月分の日割り返金は行いません）。

# {条}（免責）
1. 会員間の交流・取引・連絡に関する責任は当該当事者が負い、当方は関与しません。
2. 当方は、本サービスの中断・障害・データ消失等により生じた損害について、当方の故意または重過失による場合を除き責任を負いません。

# {条}（規約の変更）
当方は必要に応じて本規約を変更できます。変更後の規約は本ページに掲示した時点で効力を生じます。
TXT,
        ],
        'privacy' => [
            'label'   => 'プライバシーポリシー',
            'page'    => '/privacy',
            'default' => <<<'TXT'
{事業者名}（以下「当方」）は、本サービス「{サービス名}」における個人情報を以下のとおり取り扱います。

# 1. 取得する情報
- 会員：お名前、生年月日（表示は年齢のみ）、メールアドレス、職業、プロフィール本文、業種・地域・目的等のタグ、リンク（LINE追加URL・SNS等）、顔写真・カバー画像・名刺画像、LINEのユーザー識別子。
- 入会前：{入会前の取得情報}
- 決済に関する情報は決済代行（Stripe）が取得・保管します。**カード番号等の決済情報を当方は保持しません。**

# 2. 利用目的
入会手続き・本人確認・連絡、会員サイト（プロフィール表示・ディレクトリ・マッチング）の提供、月額会費の決済、公式LINEでのご案内、サービスの提供・改善、法令対応のために利用します。

# 3. 第三者提供・委託
- 決済処理のため Stripe に、メッセージ配信のため LINE（LINEヤフー株式会社）に、必要な情報を提供・委託します。
- 会員が登録したプロフィール・タグ・リンク等は、他の有効会員に対して本サービス内で表示されます。表示させたくない情報は、会員自身がプロフィール編集から削除できます。
- 上記および法令に基づく場合を除き、本人の同意なく第三者に提供しません。

# 4. 保管・安全管理
会員情報・人脈情報は、アクセス制御・通信の暗号化・保存データの適切な管理等の安全管理措置を講じて管理します。カード情報は決済事業者（Stripe）側で管理され、当方は保持しません。

# 5. 開示・訂正・削除・退会
ご本人からの請求に応じ、法令に従い保有個人データの開示・訂正・利用停止・削除に対応します。退会をご希望の場合は下記までご連絡ください。
お問い合わせ：{連絡先メール}

# 6. 改定
本ポリシーは必要に応じて改定し、本ページに掲示します。
TXT,
        ],
        'policy' => [
            'label'   => 'キャンセル・返金ポリシー',
            'page'    => '/policy',
            'default' => <<<'TXT'
本サービスの会員資格は**月額会費（サブスクリプション）**によりご利用いただけます。デジタルサービスの性質上、お支払い済みの月額会費のご返金は原則としてお受けできません。

# 月額会費について
{料金}
また、ご紹介の特典条件を満たす場合、月額会費が無料となることがあります。

# 解約について
会員サイトの「お支払い・解約の管理」から**いつでも解約**できます。解約すると次回以降の請求が停止します。現在の請求期間の終了まではご利用いただけますが、**当月分の日割り返金は行いません**。

# お支払い・カード情報の取り扱い
カード情報の入力・処理は決済代行サービス Stripe 上で安全に行われます。**当方は、カード番号・有効期限・セキュリティコードなどの決済情報を一切受け取らず、保管・閲覧もできません。**
TXT,
        ],
    ];
}

/**
 * 本文中で使える差し込み語。値が空のものは、その行（と見出し）ごと消える。
 *
 * @return array<string,string>
 */
function legal_tokens(): array
{
    return [
        '{サービス名}'       => 'Enlink',
        '{最低年齢}'         => (string) member_min_age(),
        '{事業者名}'         => legal_biz_name(),
        '{運営責任者}'       => site_setting('biz_owner'),
        '{所在地}'           => site_setting('biz_address'),
        '{連絡先メール}'     => site_setting('biz_email'),
        '{連絡先電話}'       => site_setting('biz_tel'),
        '{入会の流れ}'       => ops_signup_description(),
        '{入会前の取得情報}' => ops_precontract_data_description(),
        '{料金}'             => ops_billing_description(),
        '{自己紹介ロック}'   => ops_intro_gate_description(),
    ];
}

/** 保存済みの本文（未保存なら既定文）をそのまま返す。編集画面用。 */
function legal_body(string $key): string
{
    $defs = legal_doc_defs();
    if (!isset($defs[$key])) {
        return '';
    }
    $saved = app_setting_get('legal_' . $key, null);
    return ($saved === null || trim($saved) === '') ? $defs[$key]['default'] : $saved;
}

/** 本文を保存する。既定文と同じ内容なら未設定に戻す（＝既定に追従させる）。 */
function legal_body_save(string $key, string $body): void
{
    $defs = legal_doc_defs();
    if (!isset($defs[$key])) {
        return;
    }
    $body = mb_substr(str_replace("\r\n", "\n", $body), 0, 20000);
    $same = trim($body) === trim($defs[$key]['default']);
    app_setting_set('legal_' . $key, $same ? '' : $body);
}

/** 保存済みの本文を破棄して既定文に戻す。 */
function legal_body_reset(string $key): void
{
    if (isset(legal_doc_defs()[$key])) {
        app_setting_set('legal_' . $key, '');
    }
}

/** その文書が既定文のままか（管理画面の表示用）。 */
function legal_body_is_default(string $key): bool
{
    $saved = app_setting_get('legal_' . $key, null);
    return $saved === null || trim($saved) === '';
}

/** 表示用のHTMLを組み立てる（差し込み語の置換 → 記法の変換）。 */
function legal_body_html(string $key): string
{
    return legal_render(legal_body($key));
}

/**
 * 本文テキストをHTMLに変換する。
 * 入力は必ずエスケープしてから組み立てるので、本文にHTMLを書いても効かない。
 */
function legal_render(string $text): string
{
    $text = strtr($text, legal_tokens());
    $lines = explode("\n", str_replace("\r\n", "\n", $text));

    // 差し込み語が空になって中身が無くなった見出しは、見出しごと落とす。
    $lines = legal_drop_empty_sections($lines);
    // {条} を出現順に採番する（条文が増減しても番号がずれない）。
    $n = 0;
    foreach ($lines as $i => $line) {
        if (strpos($line, '{条}') !== false) {
            $lines[$i] = str_replace('{条}', '第' . (++$n) . '条', $line);
        }
    }

    $html = [];
    $list = null; // 'ul' | 'ol' | null
    $closeList = static function () use (&$list, &$html): void {
        if ($list !== null) {
            $html[] = '</' . $list . '>';
            $list = null;
        }
    };

    foreach ($lines as $line) {
        $line = rtrim($line);
        if (trim($line) === '') {
            $closeList();
            continue;
        }
        if (strncmp($line, '# ', 2) === 0) {
            $closeList();
            $html[] = '<h2>' . legal_inline(substr($line, 2)) . '</h2>';
            continue;
        }
        if (strncmp($line, '- ', 2) === 0) {
            if ($list !== 'ul') {
                $closeList();
                $html[] = '<ul>';
                $list = 'ul';
            }
            $html[] = '<li>' . legal_inline(substr($line, 2)) . '</li>';
            continue;
        }
        if (preg_match('/^\d+\.\s+(.*)$/u', $line, $m) === 1) {
            if ($list !== 'ol') {
                $closeList();
                $html[] = '<ol>';
                $list = 'ol';
            }
            $html[] = '<li>' . legal_inline($m[1]) . '</li>';
            continue;
        }
        $closeList();
        $html[] = '<p>' . legal_inline($line) . '</p>';
    }
    $closeList();

    return implode("\n", $html);
}

/**
 * 中身が空になった見出しを、その見出しごと取り除く。
 * （例：自己紹介ロックをOFFにすると「ご利用の条件」の条文が丸ごと消える）
 *
 * @param array<int,string> $lines
 * @return array<int,string>
 */
function legal_drop_empty_sections(array $lines): array
{
    $out = [];
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        if (strncmp($lines[$i], '# ', 2) !== 0) {
            $out[] = $lines[$i];
            continue;
        }
        // 次の見出し（または末尾）までに中身があるか。
        $hasBody = false;
        for ($j = $i + 1; $j < $count; $j++) {
            if (strncmp($lines[$j], '# ', 2) === 0) {
                break;
            }
            if (trim($lines[$j]) !== '') {
                $hasBody = true;
                break;
            }
        }
        if ($hasBody) {
            $out[] = $lines[$i];
        }
    }
    return $out;
}

/** 行内の記法（太字・リンク）を変換する。エスケープ済み文字列に対して適用する。 */
function legal_inline(string $s): string
{
    $s = e($s);
    // [表示文字](/path) — 同一サイトの相対パスと https:// のみ許可する。
    // 「//evil.com」はスキーム相対URLで外部サイトへ飛ぶため、相対パスとして通してはいけない。
    $s = preg_replace_callback(
        '/\[([^\]]+)\]\((\/[A-Za-z0-9._\/?=&#%;-]*|https:\/\/[A-Za-z0-9._\/?=&#%;:-]+)\)/u',
        static function (array $m): string {
            $href = $m[2];
            if (strncmp($href, '//', 2) === 0) {
                return $m[0]; // 外部へのスキーム相対URL：リンクにせず、書いたまま表示する
            }
            return '<a href="' . $href . '">' . $m[1] . '</a>';
        },
        $s
    ) ?? $s;
    // **強調**
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
    return $s;
}
