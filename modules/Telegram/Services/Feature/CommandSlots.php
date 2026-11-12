<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

/**
 * 固定命令槽位（商户可定制实现的命令全集）
 *
 * 命令是平台固定的「槽位」，不归任何一个功能独占：
 *   · features.merchant_id 为空的功能 = 全局默认实现（官方插件，全局唯一）；
 *   · merchant_id 非空 = 某商户的定制实现，同一商户同一命令只有一个，重传覆盖。
 *
 * 运行时（FeatureCommandDispatcher）：
 *   群内 /ye → 查本群商户号 → 优先该商户的实现 → 没有回落全局默认实现。
 *
 * 不在名单里的命令仍走「全局唯一」：装第二个就会报「命令已被其它功能占用」，
 * 保证 /fy /okxr 这类通用命令不会被商户实现稀释。
 */
class CommandSlots
{
    /**
     * 槽位命令 => 群内用途说明
     */
    public const LIST = [
        'bd' => '绑定商户',
        'jb' => '解绑商户',
        'ye' => '查询商户余额',
        'xq' => '查询代付审批限额',
        'yj' => '设置余额不足提醒',
        'xe' => '设置代付审批限额',
        'cx' => '订单查询',
        'trsq' => '设置审批人员',
        'pz' => '代付凭证',
        'ht' => '回调订单',
        'bud' => '代收补单',
    ];

    /**
     * 是否槽位命令（允许按商户多实现并存）
     */
    public static function is(string $command): bool
    {
        return array_key_exists(strtolower(ltrim(trim($command), '/')), self::LIST);
    }

    /**
     * 槽位命令的默认用途说明（插件没写 description 时的兜底）
     */
    public static function label(string $command): string
    {
        return self::LIST[strtolower(ltrim(trim($command), '/'))] ?? '';
    }
}
