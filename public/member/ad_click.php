<?php

/**
 * 広告のクリックを数えて、リンク先へ送る。
 *
 * 数えるためだけにここを通す。リンク先は登録時に検証済み（https のみ、
 * またはサイト内のパス）だが、保存後に設定を変えられた場合に備えて出る直前にも見る。
 * 検証を通らなければ、外へ飛ばさずに「さがす」へ戻す。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

require_member();

$ad = find_ad((int) ($_GET['id'] ?? 0));
if ($ad === null) {
    header('Location: /member/directory');
    exit;
}
$url = (string) $ad['url'];
if ($url === '' || !is_valid_announcement_url($url)) {
    header('Location: /member/directory');
    exit;
}
ad_count_click((int) $ad['id']);
audit_log('member.ad_click', ['id' => (int) $ad['id']]);
// 外部サイトへ送るので、参照元を渡さない（会員ページのURLを広告先に知らせない）。
header('Referrer-Policy: no-referrer');
header('Location: ' . $url, true, 302);
