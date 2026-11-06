<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Illuminate\Http\Request;
use Modules\Permissions\Support\MerchantContext;
use Modules\Telegram\Models\Merchant;

/**
 * 平台商户（租户）管理：只有平台超管能维护，商户用户看不到这张表。
 *
 * 校验放在 action 里而不是构造函数：Laravel 在 route:cache / route:list /
 * Route::gatherMiddleware() 时也会实例化控制器，构造函数里读登录态会直接把
 * artisan 命令打断（无登录态 → 一律判「无权」）。
 */
class MerchantsController extends Controller
{
    public function __construct(
        protected readonly Merchant $model
    ) {
    }

    public function index(): mixed
    {
        $this->assertPlatformSuperAdmin();

        return $this->model->getList();
    }

    public function store(Request $request): mixed
    {
        $this->assertPlatformSuperAdmin();

        return $this->model->storeBy($request->all());
    }

    public function show($id): mixed
    {
        $this->assertPlatformSuperAdmin();

        return $this->model->firstBy($id);
    }

    public function update($id, Request $request): mixed
    {
        $this->assertPlatformSuperAdmin();

        return $this->model->updateBy($id, $request->all());
    }

    public function destroy($id): mixed
    {
        $this->assertPlatformSuperAdmin();

        return $this->model->deleteBy($id);
    }

    private function assertPlatformSuperAdmin(): void
    {
        // merchants 表没有 tenant_id，DataScope 挡不住跨租户查询，必须显式收口
        if (! MerchantContext::isPlatformSuperAdmin()) {
            throw new FailedException('无权访问商户管理');
        }
    }
}
