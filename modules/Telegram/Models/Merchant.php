<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use App\Support\NullTimestamp;
use Catch\Base\CatchModel as Model;
use Illuminate\Support\Facades\Cache;
use Modules\Permissions\Models\Traits\DataRange;
use Modules\User\Models\User;

/**
 * 平台商户 / 租户。tenant_id = 0 是平台侧，不是本表的一条记录。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $status
 * @property \Illuminate\Support\Carbon|null $expired_at
 * @property \Illuminate\Support\Carbon|null $expire_noticed_at
 */
class Merchant extends Model
{
    use DataRange;

    protected $table = 'merchants';

    protected $casts = [
        'expired_at' => 'datetime',
        'expire_noticed_at' => 'date',
    ];

    protected $fillable = [
        'id', 'name', 'code', 'status', 'contact', 'expired_at', 'expire_noticed_at',
        'creator_id', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected array $fields = [
        'id', 'name', 'code', 'status', 'contact', 'expired_at', 'created_at', 'updated_at',
    ];

    protected array $form = ['name', 'code', 'status', 'contact', 'expired_at'];

    public array $searchable = [
        'name' => 'like',
        'code' => 'like',
    ];

    /**
     * expired_at / expire_noticed_at 是 integer 时间戳列，框架默认把 null 转 ''，
     * datetime cast 又对空串原样返回 → 写进整型列报 1366。空串还原成 NULL（永久有效）。
     */
    protected function filterData(array $data): array
    {
        return NullTimestamp::clean(parent::filterData($data));
    }

    public function isExpired(): bool
    {
        return $this->expired_at !== null && $this->expired_at->isPast();
    }

    protected static function booted(): void
    {
        // 有效期变更后清指令链路的判定缓存：商户自己的 key + 该商户全部用户的 key
        static::saved(function (self $merchant) {
            Cache::forget(self::expiryCacheKey((int) $merchant->id));

            User::query()->where('tenant_id', $merchant->id)->pluck('id')->each(function ($id) {
                Cache::forget(User::expiryCacheKey((int) $id));
                // 付费准入缓存同样要清：买完套餐付费功能立即解锁
                Cache::forget(\App\Support\ServiceExpiry::paidCacheKey((int) $id));
            });
        });

        static::deleted(function (self $merchant) {
            Cache::forget(self::expiryCacheKey((int) $merchant->id));
        });
    }

    public static function expiryCacheKey(int $merchantId): string
    {
        return 'merchant_expired_merchant:' . $merchantId;
    }

    // 后台列表走默认分页；顶栏下拉用 index?all=1 取全量，见 MerchantsController。
}
