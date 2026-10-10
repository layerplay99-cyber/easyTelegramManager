<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\RealMan;

/**
 * 消息私发（真人）：chatIds 里填目标用户 peer
 */
class SendMsgToPerson extends AbstractRealManFeature
{
    public static function featureKey(): string
    {
        return 'sendToPerson';
    }

    public static function featureName(): string
    {
        return '消息私发';
    }

    public static function method(): string
    {
        return 'sendToPerson';
    }
}
