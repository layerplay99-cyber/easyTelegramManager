<?php
namespace Modules\Telegram\Enums;
use Illuminate\Validation\Rules\Enum;

class LoginStatus extends Enum
{
    //已登录
    public const LOGINED = 1;

    //未登录
    public const NOTLOGIN = 0;

    //等待输入验证码
    public const WAITINPUTCODE = 2;

    //等待登录完成
    public const WAITLOGINCOMPLETE = 4;

    //登录过期
    public const LOGINEXPIRED = 3;

}
