<?php

/**
 * イベントを DB から削除する（ログイン中テナント単位）。管理画面からの POST のみ。
 * （過去の申込・決済データは Stripe 側に残るため、名簿・返金は引き続き参照可能）
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$tenant = require_tenant();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST のみ許可されています。');
}

csrf_verify($_POST['csrf_token'] ?? null);

$id = trim((string) ($_POST['id'] ?? ''));

function back(string $msg, string $type): never
{
    header('Location: events.php?' . http_build_query(['msg' => $msg, 'type' => $type]), true, 303);
    exit;
}

if ($id === '') {
    back('削除対象が指定されていません。', 'ng');
}

if (!delete_event($tenant['id'], $id)) {
    audit_log('authz.deny', ['action' => 'event.delete', 'tenant' => $tenant['id'], 'event' => $id]);
    back('対象のイベントが見つかりませんでした。', 'ng');
}

audit_log('event.delete', ['tenant' => $tenant['id'], 'event' => $id]);
back('イベントを削除しました。', 'ok');
