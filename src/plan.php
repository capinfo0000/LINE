<?php

/**
 * サブスクのプラン別 機能ゲート（一元管理）。
 *
 * 方針:
 *  - 無料フェーズ（会員100名以下 = billing_started() が false）は全員「プレミアム相当」で全機能開放。
 *  - 課金フェーズでは members.plan（basic/premium）で機能を制限/解除する。
 *  - 各画面は plan_can()/plan_recommend_max() を呼ぶだけにして、制限ルールをここに集約する。
 */

declare(strict_types=1);

/**
 * 会員の実効プラン（'premium' または 'basic'）。
 *
 * 無料フェーズ（〜100名）は全員 premium 扱いで全機能開放。
 *
 * 課金フェーズに入ったあとは、いま有料プランが1本しかない。会費を払っている
 * 会員はその1本を契約している人なので、全機能を開ける。
 * ここを members.plan だけで判定すると、決済で plan が設定されないため
 * （set_member_plan は運営画面からしか呼ばれない）払っている会員が basic のまま
 * になり、課金が始まった瞬間に検索条件が地域だけに減る＝払った人ほど不便になる。
 *
 * 紹介特典で無料化された会員も subscription_status='active' なので同じ扱いになる。
 *
 * プランを増やすときは、members.plan に段（standard 等）を保存して、
 * 下の分岐をその値で行う形にする。
 */
function member_plan(array $member): string
{
    if (function_exists('billing_started') && !billing_started()) {
        return 'premium'; // 無料フェーズ（〜100名）は全機能開放
    }
    $p = strtolower((string) ($member['plan'] ?? ''));
    if ($p === 'premium') {
        return 'premium'; // 運営が個別に上げた場合
    }
    if ((string) ($member['subscription_status'] ?? '') === 'active') {
        return 'premium'; // 会費を払っている（または紹介特典で無料化された）
    }
    return 'basic';
}

/** プランごとの機能制限テーブル。recommend_max は 0 で無制限。 */
function plan_limits(string $plan): array
{
    if ($plan === 'premium') {
        return [
            'recommend_max'  => 0,     // 0 = 無制限
            'search_full'    => true,  // 全条件で検索
            'priority'       => true,  // 一覧で上位表示
            'see_interested' => true,  // 「興味を持たれた一覧」（フェーズ2）
        ];
    }
    // basic（既定）
    return [
        'recommend_max'  => max(0, (int) env('PLAN_BASIC_RECOMMEND_MAX', '5')),
        'search_full'    => false,
        'priority'       => false,
        'see_interested' => false,
    ];
}

/** 会員のbool系機能が使えるか（search_full / priority / see_interested）。 */
function plan_can(array $member, string $feature): bool
{
    $lim = plan_limits(member_plan($member));
    return (bool) ($lim[$feature] ?? false);
}

/** おすすめの表示上限（0 = 無制限）。 */
function plan_recommend_max(array $member): int
{
    return (int) (plan_limits(member_plan($member))['recommend_max'] ?? 0);
}

/** プランの日本語ラベル。 */
function plan_label(string $plan): string
{
    return $plan === 'premium' ? 'プレミアム' : 'ベーシック';
}

/**
 * 会員に見せるプラン表記。
 * 無料フェーズ中は内部的に全員 premium 扱いだが、そのまま「プレミアム」と出すと
 * 課金していないのに有料プランに見えて紛らわしいので「無料期間中」と表示する。
 */
function member_plan_label(array $member): string
{
    return billing_started() ? plan_label(member_plan($member)) : '無料期間中';
}

/** 会員のプランを設定する（運営操作／Webhook用）。basic/premium のみ受け付ける。 */
function set_member_plan(string $memberId, string $plan): void
{
    $plan = $plan === 'premium' ? 'premium' : 'basic';
    db()->prepare('UPDATE members SET plan = ? WHERE id = ?')->execute([$plan, $memberId]);
}
