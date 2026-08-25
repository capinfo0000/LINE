<?php

/**
 * SNS のリンクプレビュー（OGP / Twitter Card）と、検索・AI向けのメタ情報。
 *
 * LINE・X・Facebook などにURLを貼ったときのカード表示は、すべて <head> の
 * og:* を読んで作られる。何も書いていないと画像も説明も出ず、<title> だけの
 * 寂しいリンクになるため、公開ページには必ず入れる。
 *
 * 画像は絶対URL（https://〜）でないと読まれない。ここで base_url() を足す。
 */

declare(strict_types=1);

/** リンクプレビューに使う画像のパス（1200×630）。 */
const OG_IMAGE_PATH = '/assets/og.png';

/** サービスの既定の説明文（og:description / meta description の既定値）。 */
function site_tagline(): string
{
    return '「提供できること」と「求めていること」が噛み合う相手だけに出会える、'
        . '会員制のビジネスマッチング。紹介・協業・販路開拓の相手を探せます。';
}

/**
 * 現在のリクエストのパス（クエリを除く）。canonical と og:url に使う。
 * クエリ付きのURLを別ページとして扱わせないため、? 以降は落とす。
 */
function current_path(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return $path === '' ? '/' : $path;
}

/**
 * <head> に入れるメタ情報をまとめて返す。
 *
 * @param array{
 *   title?: string,        ページ固有の見出し（省略時はサービス名だけ）
 *   description?: string,  説明文（省略時は site_tagline()）
 *   path?: string,         canonical / og:url のパス（省略時は現在のパス）
 *   noindex?: bool,        会員ページ・運営ページは true（検索させない）
 *   type?: string,         og:type（既定 website）
 * } $o
 */
function page_meta_tags(array $o = []): string
{
    $base = rtrim(base_url(), '/');
    $siteName = 'Enlink';
    $title = trim((string) ($o['title'] ?? ''));
    $ogTitle = $title !== '' ? $title . '｜' . $siteName : $siteName . ' — ビジネスの縁が、ここから。';
    $desc = trim((string) ($o['description'] ?? '')) !== '' ? (string) $o['description'] : site_tagline();
    $path = (string) ($o['path'] ?? current_path());
    $url = $base . $path;
    $noindex = !empty($o['noindex']);
    $type = (string) ($o['type'] ?? 'website');

    $out = [];
    $out[] = '<meta name="description" content="' . e($desc) . '">';
    if ($noindex) {
        // 会員限定・運営用のページは検索結果にもAIの学習・引用にも載せない。
        $out[] = '<meta name="robots" content="noindex, nofollow">';
    } else {
        $out[] = '<link rel="canonical" href="' . e($url) . '">';
        $out[] = '<meta name="robots" content="index, follow, max-image-preview:large">';
    }
    $out[] = '<meta property="og:type" content="' . e($type) . '">';
    $out[] = '<meta property="og:site_name" content="' . e($siteName) . '">';
    $out[] = '<meta property="og:locale" content="ja_JP">';
    $out[] = '<meta property="og:title" content="' . e($ogTitle) . '">';
    $out[] = '<meta property="og:description" content="' . e($desc) . '">';
    $out[] = '<meta property="og:url" content="' . e($url) . '">';
    $out[] = '<meta property="og:image" content="' . e($base . OG_IMAGE_PATH) . '">';
    $out[] = '<meta property="og:image:secure_url" content="' . e($base . OG_IMAGE_PATH) . '">';
    $out[] = '<meta property="og:image:type" content="image/png">';
    $out[] = '<meta property="og:image:width" content="1200">';
    $out[] = '<meta property="og:image:height" content="630">';
    $out[] = '<meta property="og:image:alt" content="' . e($siteName . ' — ビジネスの縁が、ここから。') . '">';
    // Twitter/X は og:* も読むが、カードの大きさだけは専用の指定が必要。
    $out[] = '<meta name="twitter:card" content="summary_large_image">';
    $out[] = '<meta name="twitter:title" content="' . e($ogTitle) . '">';
    $out[] = '<meta name="twitter:description" content="' . e($desc) . '">';
    $out[] = '<meta name="twitter:image" content="' . e($base . OG_IMAGE_PATH) . '">';
    $out[] = '<meta name="theme-color" content="#1b2a4a">';

    return implode("\n    ", $out) . "\n";
}

/**
 * 構造化データ（JSON-LD）。検索エンジンとAIに「何をしている事業者か」を伝える。
 *
 * 事業者情報（各種設定）が未入力のうちは、その項目を出さない。
 * 空欄を並べるより、入っているものだけ書いたほうが正確に読まれる。
 * 設定を入れれば自動で埋まる。
 */
function site_jsonld(): string
{
    $base = rtrim(base_url(), '/');
    $org = [
        '@type' => 'Organization',
        'name'  => site_setting('biz_name') !== '' ? site_setting('biz_name') : 'Enlink',
        'url'   => $base . '/',
        'logo'  => $base . OG_IMAGE_PATH,
    ];
    if (site_setting('biz_email') !== '' || site_setting('biz_tel') !== '') {
        $contact = ['@type' => 'ContactPoint', 'contactType' => 'customer support'];
        if (site_setting('biz_email') !== '') {
            $contact['email'] = site_setting('biz_email');
        }
        if (site_setting('biz_tel') !== '') {
            $contact['telephone'] = site_setting('biz_tel');
        }
        $org['contactPoint'] = $contact;
    }
    if (site_setting('biz_address') !== '') {
        $org['address'] = ['@type' => 'PostalAddress', 'streetAddress' => site_setting('biz_address')];
    }

    $data = [
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type'       => 'WebSite',
                'name'        => 'Enlink',
                'url'         => $base . '/',
                'description' => site_tagline(),
                'inLanguage'  => 'ja',
            ],
            $org,
        ],
    ];

    // JSON_HEX_TAG で </script> の混入を防ぐ（設定値がそのまま入るため）。
    $json = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if ($json === false) {
        return '';
    }
    return '<script type="application/ld+json" nonce="' . e(csp_nonce()) . '">' . $json . '</script>' . "\n";
}
