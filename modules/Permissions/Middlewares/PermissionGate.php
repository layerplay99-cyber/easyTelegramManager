<?php

namespace Modules\Permissions\Middlewares;

use Illuminate\Http\Request;
use Modules\Permissions\Exceptions\PermissionForbidden;
use Modules\User\Models\User;

class PermissionGate
{
    public function handle(Request $request, \Closure $next)
    {
        if ($request->isMethod('get')) {
            return $next($request);
        }

        /* @var User|null $user */
        $user = $request->user(getGuardName());

        // 原来没有判空，未认证请求会直接 "Call to a member function can() on null" 抛 500。
        // 未登录的情况交给 Auth 中间件处理，这里直接拒绝即可。
        if (! $user) {
            throw new PermissionForbidden();
        }

        if (! $user->can()) {
            throw new PermissionForbidden();
        }

        return $next($request);
    }
}
