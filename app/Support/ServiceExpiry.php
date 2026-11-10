<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Models\Merchant;
use Modules\User\Models\User;

/**
 * 服务有效期综合判定（后台接口 / Telegram 指令链路共用）
 *
 * 规则：
 *   - 用户自身 users.expired_at 过期 → 过期（单账号封顶）
 *   - 所属商户 merchants.expired_at 过期 → 过期（整店生效）
 *   - 两者任一命中即过期；平台侧用户（tenant_id = 0）只看自身
 *   - 平台超管的豁免由调用方自行处理（中间件 / UpdateRouter 都有 isSuperAdmin 判断）
 *
 * 缓存：商户级判定缓存 5 分钟，key 见 Merchant::expiryCacheKey；
 * 用户级 key 见 User::expiryCacheKey（包住整段综合判定）。
 * Merchant / User 的 saved 钩子会主动清缓存，后台改完有效期立即生效。
 */
class ServiceExpiry
{
    /**
     * 是否处于「有效套餐期内」——付费功能的准入凭据
     *
     * 与 expiredFor（过期拦截）语义不同：
     *   - expiredFor：expired_at 为空 = 永久有效，任何功能都能用；
     *   - hasPaidAccess：必须有明确的有效期且未到期，即买过套餐且在期内。
     *     没买过（expired_at 空）只能使用免费功能。
     *
     * 商户级或用户级任一在期内即可（购买套餐延长的是商户级）。
     */
    public static function hasPaidAccess(User $user): bool
    {
        return Cache::remember(
            self::paidCacheKey((int) $user->id),
            now()->addMinutes(5),
            function () use ($user) {
                if ($user->expired_at !== null && $user->expired_at->isFuture()) {
                    return true;
                }

                $tenantId = (int) $user->tenant_id;

                if ($tenantId === 0) {
                    return false;
                }

                $merchant = Merchant::query()->find($tenantId);

                return $merchant?->expired_at !== null && $merchant->expired_at->isFuture();
            }
        );
    }

    /**
     * 付费准入缓存的 key（User / Merchant 的 saved 钩子会清）
     */
    public static function paidCacheKey(int $userId): string
    {
        return 'paid_access:' . $userId;
    }

    public static function expiredFor(User $user): bool
    {
        return Cache::remember(
            User::expiryCacheKey((int) $user->id),
            now()->addMinutes(5),
            function () use ($user) {
                if ($user->isExpired()) {
                    return true;
                }

                $tenantId = (int) $user->tenant_id;

                if ($tenantId === 0) {
                    return false;
                }

                return Cache::remember(
                    Merchant::expiryCacheKey($tenantId),
                    now()->addMinutes(5),
                    fn () => Merchant::query()->find($tenantId)?->isExpired() ?? false
                );
            }
        );
    }
}
