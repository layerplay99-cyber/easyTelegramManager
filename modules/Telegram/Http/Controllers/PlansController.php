<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Illuminate\Http\Request;
use Modules\Permissions\Support\MerchantContext;
use Modules\Telegram\Models\Plan;

/**
 * 套餐管理：列表对所有登录用户开放（购买弹框要选套餐），
 * 增删改仅平台超管。
 *
 * 校验放 action 里而不是构造函数：route:cache / route:list 时也会实例化
 * 控制器，构造函数读登录态会打断 artisan 命令（同 MerchantsController 注释）。
 */
class PlansController extends Controller
{
    public function __construct(
        protected readonly Plan $model
    ) {
    }

    public function index(Request $request): mixed
    {
        // 购买弹框用 all=1 取上架套餐（不分页）
        if ($request->boolean('all')) {
            return Plan::query()
                ->where('status', 1)
                ->orderByDesc('sort')
                ->orderBy('id')
                ->get(['id', 'name', 'days', 'price', 'remark']);
        }

        return $this->model->getList();
    }

    public function store(Request $request): mixed
    {
        $this->assertPlatformSuperAdmin();

        return $this->model->storeBy($this->validated($request));
    }

    public function show($id): mixed
    {
        return $this->model->firstBy($id);
    }

    public function update($id, Request $request): mixed
    {
        $this->assertPlatformSuperAdmin();

        return $this->model->updateBy($id, $this->validated($request));
    }

    public function destroy($id): mixed
    {
        $this->assertPlatformSuperAdmin();

        return $this->model->deleteBy($id);
    }

    private function validated(Request $request): array
    {
        $name = trim((string) $request->input('name', ''));
        $days = (int) $request->input('days', 0);
        $price = (string) $request->input('price', '0');

        if ($name === '') {
            throw new FailedException('套餐名称不能为空');
        }

        if ($days <= 0 || $days > 3650) {
            throw new FailedException('有效天数必须在 1 ~ 3650 之间');
        }

        if (! is_numeric($price) || bccomp($price, '0', 8) < 0) {
            throw new FailedException('价格必须是不小于 0 的数字');
        }

        return [
            'name' => $name,
            'days' => $days,
            'price' => $price,
            'status' => $request->has('status') ? ($request->boolean('status') ? 1 : 0) : 1,
            'sort' => (int) $request->input('sort', 0),
            'remark' => trim((string) $request->input('remark', '')),
        ];
    }

    private function assertPlatformSuperAdmin(): void
    {
        if (! MerchantContext::isPlatformSuperAdmin()) {
            throw new FailedException('无权维护套餐');
        }
    }
}
