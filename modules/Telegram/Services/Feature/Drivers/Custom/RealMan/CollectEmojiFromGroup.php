<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\RealMan;

/**
 * 表情包采集（真人）：记录群与真人账号的采集关系，供后续群发引用表情
 */
class CollectEmojiFromGroup extends AbstractRealManFeature
{
    public static function featureKey(): string
    {
        return 'collectEmoji';
    }

    public static function featureName(): string
    {
        return '表情包采集';
    }

    public static function method(): string
    {
        return 'collectEmoji';
    }

    public static function defaultConfig(): array
    {
        return ['method' => static::method()];
    }
}
