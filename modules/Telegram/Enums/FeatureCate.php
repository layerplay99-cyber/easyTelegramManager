<?php
namespace Modules\Telegram\Enums;
use Illuminate\Validation\Rules\Enum;

class FeatureCate extends Enum
{
    //客户端
    public const CUSTOM = 'custom';

    //系统
    public const SYSTEM = 'system';

    //机器人
    public const BOT = 'bot';

    //真人
    public const REALMAN = 'realMan';


}
