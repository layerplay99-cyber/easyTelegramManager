<?php

namespace Modules\Telegram\Enums;

use Illuminate\Validation\Rules\Enum;
class TransferOrderType extends Enum
{
    /**
     * 锁定
     */
    public const LOCKED = 'locked';

    /**
     * 解锁
     */
    public const UNLOCKED = 'unlocked';

    /**
     * 标记成功
     */
    public const MARK_SUCCESS = 'success';

    /**
     * 标记失败
     */
    public const MARK_FAIL = 'fail';

    /**
     * 扫码
     */
    public const QRCODE = 'qrcode';

    /**
     * 待处理
     */
    public const PENDING = 'pending';

    /**
     * 成功
     */
    public const SUCCESS = 'success';

    /**
     * 失败
     */
    public const FAILED = 'failed';

}
