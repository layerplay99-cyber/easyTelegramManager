<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Base;

use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;

/**
 * 基础功能基类
 *
 * features.category = 'base' 的功能不参与功能分配（不需要在后台把功能
 * 绑定到机器人/群），FeatureExecutor 对它们跳过绑定校验，所有机器人、
 * 所有群默认可用。上架开关仍在后台「功能列表」控制。
 */
abstract class AbstractBaseFeature extends BaseCustomFeature
{
    public static function category(): string
    {
        return 'base';
    }

    public static function needsUpstream(): bool
    {
        return false;
    }

    public static function group(): string
    {
        return '基础';
    }

    /**
     * 基础功能不暴露可配项：行为由命令参数 / 群编辑开关控制
     */
    public static function configSchema(): array
    {
        return [];
    }

    public static function label(): string
    {
        return static::featureName();
    }

    public static function defaultConfig(): array
    {
        return ['scope_type' => 'group'];
    }
}
