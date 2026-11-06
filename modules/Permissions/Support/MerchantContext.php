<?php

declare(strict_types=1);

namespace Modules\Permissions\Support;

use Catch\Exceptions\FailedException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 当前请求的租户上下文
 *
 * 规则：
 *  - 有后台登录态 → 取 users.tenant_id；
 *  - 无登录态（队列 / CLI / 免登录接口）→ null，DataScope 完全放行，
 *    群发链路按 bot_id / chat_id 精确定位，不依赖全表可见范围；
 *  - 平台超管（id = catch.super_admin 且 tenant_id = 0）→ 不受限制；
 *  - 平台超管可在创建 / 编辑时用请求参数 tenant_id 显式指定归属商户。
 *
 * 注意：Auth::id() 走默认 web guard，API 请求恒为 null，必须用 getGuardName()。
 */
class MerchantContext
{
    /** CLI / 队列显式指定的租户 */
    protected static ?int $override = null;

    public static function user(): mixed
    {
        return Auth::guard(getGuardName())->user();
    }

    /** @return int|null null 表示无登录上下文 */
    public static function current(): ?int
    {
        if (static::$override !== null) {
            return static::$override;
        }

        $user = static::user();

        return $user ? (int) ($user->tenant_id ?? 0) : null;
    }

    public static function isPlatformSuperAdmin(mixed $user = null): bool
    {
        $user = $user ?: static::user();

        if (! $user) {
            return false;
        }

        return (int) $user->id === (int) config('catch.super_admin')
            && (int) ($user->tenant_id ?? 0) === 0;
    }

    public static function isMerchantAdmin(mixed $user = null): bool
    {
        $user = $user ?: static::user();

        return $user
            && (int) ($user->tenant_id ?? 0) > 0
            && (bool) ($user->is_merchant_admin ?? false);
    }

    /**
     * 新建记录时的 tenant_id
     *
     * 只有平台超管能指定归属商户；商户用户一律继承自己的租户，
     * 请求里带别的 tenant_id 也忽略，杜绝「商户把数据建到别的商户名下」。
     */
    public static function forCreate(): int
    {
        if (static::isPlatformSuperAdmin()) {
            $raw = static::requestedTenantId();

            if ($raw !== null && $raw !== '') {
                $tenantId = (int) $raw;

                if ($tenantId !== 0 && ! self::merchantExists($tenantId)) {
                    throw new FailedException('归属商户不存在');
                }

                return $tenantId;
            }
        }

        return static::current() ?? 0;
    }

    /**
     * 编辑时是否允许变更归属。只有平台超管可以，且值必须合法。
     *
     * @return int|null null 表示本次请求没有指定归属变更
     */
    public static function requestedTransfer(): ?int
    {
        if (! static::isPlatformSuperAdmin()) {
            return null;
        }

        $raw = static::requestedTenantId();

        if ($raw === null || $raw === '') {
            return null;
        }

        $tenantId = (int) $raw;

        if ($tenantId !== 0 && ! self::merchantExists($tenantId)) {
            throw new FailedException('归属商户不存在');
        }

        return $tenantId;
    }

    public static function set(?int $tenantId): void
    {
        static::$override = $tenantId;
    }

    public static function forget(): void
    {
        static::$override = null;
    }

    public static function runWith(?int $tenantId, callable $callback): mixed
    {
        $previous = static::$override;
        static::$override = $tenantId;

        try {
            return $callback();
        } finally {
            static::$override = $previous;
        }
    }

    private const COLUMN_FOR_REQUEST = 'tenant_id';

    private static function requestedTenantId(): mixed
    {
        try {
            return request()->input(self::COLUMN_FOR_REQUEST);
        } catch (\Throwable) {
            // 纯 CLI 上下文里 request 可能没绑定
            return null;
        }
    }

    private static function merchantExists(int $tenantId): bool
    {
        try {
            return DB::table('merchants')->where('id', $tenantId)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
