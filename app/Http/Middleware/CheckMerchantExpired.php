<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ServiceExpiry;
use Catch\Exceptions\FailedException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 商户有效期拦截（挂在 config/catch.php 的 catch.route.middlewares，AuthMiddleware 之后）
 *
 * 用户 expired_at（空 = 永久）过期后，全部后台接口返回「服务已到期」。
 * login / logout 等接口在 withoutMiddleware 白名单里不受影响——登录入口
 * 自己有 isExpired 校验，提示更直接；过期用户就算登进来，第一个业务请求
 * 也会被这里拦下。
 * 平台超管（catch.super_admin）不受有效期限制。
 */
class CheckMerchantExpired
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Auth::guard(getGuardName())->user();

        if ($user && ! $user->isSuperAdmin() && ServiceExpiry::expiredFor($user)) {
            throw new FailedException('服务已到期，请联系平台管理员续期');
        }

        return $next($request);
    }
}
