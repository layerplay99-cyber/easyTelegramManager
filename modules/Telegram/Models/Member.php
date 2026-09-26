<?php

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Support\Facades\Hash;

class Member extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'members';

    protected $fillable = [
        'telegram_user_id', 'telegram_username', 'avatar',
        'payment_password', 'register_ip', 'last_ip',
        'last_active_at', 'status', 'remark'
    ];

    protected $hidden = [
        'payment_password'
    ];

    /**
     * 状态常量
     */
    const STATUS_DISABLED = 0; // 禁用
    const STATUS_NORMAL = 1;   // 正常
    const STATUS_FROZEN = 2;   // 冻结

    /**
     * 关联钱包
     */
    public function wallets()
    {
        return $this->hasMany(Wallet::class, 'member_id');
    }

    /**
     * 获取指定币种的钱包
     */
    public function getWallet(string $currency = 'CNY')
    {
        return $this->wallets()->where('currency', $currency)->first();
    }

    /**
     * 关联充值订单
     */
    public function rechargeOrders()
    {
        return $this->hasMany(RechargeOrder::class, 'member_id');
    }

    /**
     * 关联提现订单
     */
    public function withdrawOrders()
    {
        return $this->hasMany(WithdrawOrder::class, 'member_id');
    }

    /**
     * 关联账本记录
     */
    public function ledgers()
    {
        return $this->hasMany(Ledger::class, 'member_id');
    }

    /**
     * 设置支付密码
     */
    public function setPaymentPassword(string $password): void
    {
        $this->payment_password = Hash::make($password);
        $this->save();
    }

    /**
     * 验证支付密码
     */
    public function verifyPaymentPassword(string $password): bool
    {
        if (empty($this->payment_password)) {
            return false;
        }
        return Hash::check($password, $this->payment_password);
    }

    /**
     * 更新最后活跃时间
     */
    public function updateLastActive(?string $ip = null): void
    {
        $this->last_active_at = time();
        if ($ip) {
            $this->last_ip = $ip;
        }
        $this->save();
    }

    /**
     * 是否正常状态
     */
    public function isNormal(): bool
    {
        return $this->status === self::STATUS_NORMAL;
    }

    /**
     * 是否冻结状态
     */
    public function isFrozen(): bool
    {
        return $this->status === self::STATUS_FROZEN;
    }

    /**
     * 冻结账户
     */
    public function freeze(string $reason = ''): void
    {
        $this->status = self::STATUS_FROZEN;
        if ($reason) {
            $this->remark = $reason;
        }
        $this->save();
    }

    /**
     * 解冻账户
     */
    public function unfreeze(): void
    {
        $this->status = self::STATUS_NORMAL;
        $this->save();
    }
}
