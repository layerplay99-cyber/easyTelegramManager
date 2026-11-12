<?php

declare(strict_types=1);

namespace Modules\Permissions\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Enums\Status;
use Catch\Exceptions\FailedException;
use Illuminate\Database\Eloquent\Model;
use Modules\Permissions\Enums\MenuType;
use Modules\Permissions\Models\Permissions;
use Illuminate\Http\Request;

class PermissionsController extends Controller
{
    /**
     * @param Permissions $model
     */
    public function __construct(
        protected readonly Permissions $model
    ) {
    }

    /**
     * 菜单结构的写操作仅限超级管理员
     *
     * 列表（index）刻意保持全量不过滤：角色分配权限时需要看到完整的菜单树，
     * RolesController::index 的 from=role 分支也依赖它。
     * 但增删改 / 启停会让部门管理员改到别人正在使用的菜单结构，
     * 所以在写入口统一按死，读入口放开。
     */
    protected function assertSuperAdmin(): void
    {
        if (! $this->getLoginUser()->isSuperAdmin()) {
            throw new FailedException('仅超级管理员可操作菜单');
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request): mixed
    {
        if ($request->get('from') == 'role') {
            return $this->model->setBeforeGetList(function ($query){
                return $query->orderByDesc('sort');
            })->getList();
        }

        return $this->model->setBeforeGetList(function ($query) {
            return $query->with('actions')->whereIn('type', [MenuType::Top->value(), MenuType::Menu->value()])->orderByDesc('sort');
        })->getList();
    }

    /**
     *
     * @param Request $request
     * @return mixed
     * @throws \ReflectionException
     */
    public function store(Request $request)
    {
        $this->assertSuperAdmin();

        return $this->model->storeBy($request->all());
    }

    /**
     *
     * @param $id
     * @return Model|null
     */
    public function show($id): ?Model
    {
        return $this->model->firstBy($id);
    }

    /**
     *
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request): mixed
    {
        $this->assertSuperAdmin();

        return $this->model->updateBy($id, $request->all());
    }

    /**
     *
     * @param $id
     * @return mixed
     */
    public function destroy($id)
    {
        $this->assertSuperAdmin();

        if ($this->model->where($this->model->getParentIdColumn(), $id)->first()) {
            throw new FailedException('无法进行删除，请先删除子级');
        }

        return $this->model->deleteBy($id);
    }

    /**
     * enable
     *
     * @param $id
     * @return bool
     */
    public function enable($id): bool
    {
        $this->assertSuperAdmin();

        // 框架语义：1=显示(Enable) / 2=隐藏(Disable)，isHidden() 只认 2。
        // 不用 toggleBy：它把「当前值!=1」一律写成 1，历史存量 hidden=0 的菜单
        // 点一次「隐藏」会变成 1（还是显示），要点两次才生效。
        // 这里任何起始值（0/1）都直接切到 2，2 切回 1，一次到位。
        $current = (int) $this->model->where('id', $id)->value('hidden');

        $hidden = $current === Status::Disable->value()
            ? Status::Enable->value()
            : Status::Disable->value();

        // 只改当前菜单，不级联子节点：隐藏父级目录后整组本来就不渲染，
        // 级联反而会把子节点各自的隐藏/显示状态冲掉
        return $this->model->where('id', $id)->update(['hidden' => $hidden]) > 0;
    }
}
