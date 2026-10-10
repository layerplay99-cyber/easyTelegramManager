<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\RealMan;

/**
 * 群内@发送（真人）：额外需要 mention_ids（缺省退化成 user_id）
 */
class SendMsgToGroupPerson extends AbstractRealManFeature
{
    public static function featureKey(): string
    {
        return 'sendToGroupPerson';
    }

    public static function featureName(): string
    {
        return '消息群内@发送';
    }

    public static function method(): string
    {
        return 'sendToGroupPerson';
    }
}
