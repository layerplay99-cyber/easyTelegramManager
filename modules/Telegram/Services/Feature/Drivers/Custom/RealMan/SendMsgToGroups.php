<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\RealMan;

/**
 * 消息群发（真人）：把 chatIds 逐个派发发送任务
 */
class SendMsgToGroups extends AbstractRealManFeature
{
    public static function featureKey(): string
    {
        return 'sendToGroups';
    }

    public static function featureName(): string
    {
        return '消息群发';
    }

    public static function method(): string
    {
        return 'send';
    }
}
