<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\RealMan;

/**
 * 踢除群员（真人）：需要 user_id，且该真人账号要有管理员权限
 */
class KickOutGroup extends AbstractRealManFeature
{
    public static function featureKey(): string
    {
        return 'kickOutGroup';
    }

    public static function featureName(): string
    {
        return '踢除群员';
    }

    public static function method(): string
    {
        return 'kick';
    }

    public static function defaultConfig(): array
    {
        return ['method' => static::method()];
    }
}
