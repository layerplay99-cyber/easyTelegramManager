<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\RealMan;

/**
 * 快捷回复（真人）：需要 reply_to_msg_id
 */
class FastReplayMsg extends AbstractRealManFeature
{
    public static function featureKey(): string
    {
        return 'fastReplayMsg';
    }

    public static function featureName(): string
    {
        return '快捷回复';
    }

    public static function method(): string
    {
        return 'reply';
    }

    public static function defaultConfig(): array
    {
        return ['method' => static::method()];
    }
}
