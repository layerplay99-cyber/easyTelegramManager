<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Permissions\Support\MerchantContext;
use Modules\Permissions\Support\Tenancy;
use Modules\Telegram\Models\Merchant;

/**
 * 平台商户（租户）管理：只有平台超管能维护，商户用户看不到这张表。
 *
 * 校验放在 action 里而不是构造函数：Laravel 在 route:cache / route:list /
 * Route::gatherMiddleware() 时也会实例化控制器，构造函数里读登录态会直接把
 * artisan 命令打断（无登录态 → 一律判「无权」）。
 *
 * 这里只维护商户本身，不在创建商户时顺带建账号：账号统一在「管理员列表」
 * 创建（那里可同时指定归属商户、商户超管标记和角色），避免两个入口各建一套
 * 语义不一致的账号，尤其是「建了账号却没角色、登录后侧边栏空白」的半成品。
 * 命令行 merchant:create 只保留为批量初始化兜底。
 */
class MerchantsController extends Controller
{
    public function __construct(
        protected readonly Merchant $model
    ) {
    }

    /**
     * 后台列表走分页；顶栏「归属商户」下拉带 all=1 取全量。
     *
     * 不另开 options 路由：复用已有资源路由，避免 route:cache 环境下新路由
     * 没刷新导致 404（见 docs/deployment.md 第 12 节）。
     */
    public function index(Request $request): mixed
    {
        $this->assertPlatformSuperAdmin();

        if ($request->boolean('all')) {
            return Merchant::query()
                ->select(['id', 'name', 'code', 'status'])
                ->orderBy('id')
                ->get();
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
        $this->assertPlatformSuperAdmin();

        return $this->model->firstBy($id);
    }

    public function update($id, Request $request): mixed
    {
        $this->assertPlatformSuperAdmin();

        $data = $this->validated($request, (int) $id);

        return $this->model->updateBy($id, $data);
    }

    public function destroy($id): mixed
    {
        $this->assertPlatformSuperAdmin();

        $this->assertNoTenantData((int) $id);

        return $this->model->deleteBy($id);
    }

    /**
     * 短码是租户的稳定外部标识，必须唯一且格式可控。
     *
     * 用 FailedException 而不是 abort(422) / ValidationException：Catch 的异常
     * 处理器会把它们包成 HTTP 200 + code 10005，前端只能拿到通用文案，
     * 看不到「短码已存在」这类具体原因。
     */
    private function validated(Request $request, ?int $id = null): array
    {
        $name = trim((string) $request->input('name', ''));
        $code = trim((string) $request->input('code', ''));

        if ($name === '' || $code === '') {
            throw new FailedException('商户名称和短码不能为空');
        }

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code)) {
            throw new FailedException('短码只能包含字母、数字、下划线和短横线，最长 64 位');
        }

        $duplicate = Merchant::query()
            ->where('code', $code)
            ->when($id, fn ($query) => $query->where('id', '<>', $id))
            ->exists();

        if ($duplicate) {
            throw new FailedException('商户短码已存在：' . $code);
        }

        $data = [
            'name'    => $name,
            'code'    => $code,
            'contact' => trim((string) $request->input('contact', '')),
        ];

        if ($request->has('status')) {
            $data['status'] = $request->boolean('status') ? 1 : 0;
        } elseif ($id === null) {
            $data['status'] = 1;
        }

        return $data;
    }

    /**
     * 商户被删掉后，所有 tenant_id = 该 id 的记录会变成悬空归属（没有外键约束）。
     * 删之前把带 tenant_id 的业务表都扫一遍，有数据就拒绝。
     */
    private function assertNoTenantData(int $merchantId): void
    {
        foreach (Tenancy::TABLES as $table) {
            if (in_array($table, Tenancy::SHARED_TABLES, true)) {
                continue;
            }

            if (! Schema::hasColumn($table, Tenancy::COLUMN)) {
                continue;
            }

            if (DB::table($table)->where(Tenancy::COLUMN, $merchantId)->exists()) {
                throw new FailedException('该商户下已有数据（' . $table . '），不能删除；请先停用或转移数据');
            }
        }
    }

    private function assertPlatformSuperAdmin(): void
    {
        // merchants 表没有 tenant_id，DataScope 挡不住跨租户查询，必须显式收口
        if (! MerchantContext::isPlatformSuperAdmin()) {
            throw new FailedException('无权访问商户管理');
        }
    }
}
