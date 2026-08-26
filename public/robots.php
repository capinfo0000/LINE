<?php

/**
 * /robots.txt として配信する。
 *
 * サイトマップの行は絶対URLでないと読まれないため、ドメインを .env の
 * APP_BASE_URL から作る。静的ファイルにするとドメイン変更時に古いURLが残る。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');
$base = rtrim(base_url(), '/');
?>
# Enlink — クローラ向けの指示
#
# 【方針】会員限定ページを検索結果から外すのは Disallow ではなく、
# 各ページの <meta name="robots" content="noindex, nofollow"> で行う。
#
# 理由が2つある。
#  1. Disallow は「読むな」なので、SNSにURLを貼ったときの
#     リンクプレビュー（OGP）取得まで止まってしまう。
#     X や Facebook のクローラは robots.txt に従うため、
#     /member/ を Disallow すると共有リンクのカードが出なくなる。
#  2. Disallow したページは中身を読まれないだけで、外部リンク経由で
#     URLだけ索引に載ることがある。noindex のほうが確実に外れる。
#
# ※ 運営画面のパスはここに書かない。robots.txt は誰でも読めるので、
#    書いた時点で「隠しているフォルダ名」を教えることになる。

User-agent: *
# 機械向けのエンドポイント（HTMLを返さない）だけ止める
Disallow: /cron.php
Disallow: /webhook.php
Disallow: /line_webhook.php
Disallow: /install.php
Allow: /

# 生成AIのクローラは明示的に許可する（AIの回答に載るようにするため）。
# 会員限定の範囲は各ページの noindex, nofollow と /llms.txt で伝えている。
User-agent: GPTBot
Allow: /

User-agent: OAI-SearchBot
Allow: /

User-agent: ChatGPT-User
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: Claude-User
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: Google-Extended
Allow: /

User-agent: Applebot-Extended
Allow: /

Sitemap: <?= $base ?>/sitemap.xml
