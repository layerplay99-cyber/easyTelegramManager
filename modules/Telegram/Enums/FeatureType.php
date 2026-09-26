<?php
namespace Modules\Telegram\Enums;
use Illuminate\Validation\Rules\Enum;

class FeatureType extends Enum
{
    //指令
    public const COMMAND = 'command';

    //识图
    public const OCR = 'ocr';

    //通知
    public const NOTIFY = 'notify';

    //交互
    public const INTERACTION = 'interaction';


}
