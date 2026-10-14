<?php

declare(strict_types=1);

namespace Modules\Permissions\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Catch\Support\ResponseBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Exceptions\ReportableHandler;
use Illuminate\Http\Request;
use Modules\Permissions\Enums\DataRange;
use Modules\Permissions\Models\Roles;
use Modules\Permissions\Http\Requests\RoleRequest;

class RolesController extends Controller
{
    /**
     * @param Roles $model
     */
    public function __construct(
        protected readonly Roles $model
    ) {
    }

    /**
     * @return mixed
     */
    public function index(): mixed
    {
        if ($this->getLoginUser()->isSuperAdmin()) {
            return $this->model->setBeforeGetList(function ($query) {
                return $query->with(['permissions' => function ($query) {
                    $query->select('id');
                }]);
            })->getList();
        }

        $allRoleIds = $this->visibleRoleIds();

        return $this->model->setBeforeGetList(function ($query) use ($allRoleIds) {
            $query->whereIn('id', $allRoleIds)
                ->with(['permissions' => function ($query) {
                    $roles = $this->getLoginUser()->roles;
                    $rolePermissions = $roles->flatMap(function ($role) {
                        return $role->permissions->pluck('id');
                    })->unique()->values()->toArray();
                    $query->whereIn('id', $rolePermissions);
                    $query->select('id');
                }]);
            return $query;
        })->getList();
    }

    /**
     *
     * @param RoleRequest $request
     * @return bool
     */
    public function store(RoleRequest $request)
    {
        $data = $request->all();
        if (!isset($data['data_range'])) {
            $data['data_range'] = 0;
        } else {
            $data['data_range'] = (int)$data['data_range'];
            if (!DataRange::Personal_Choose->assert($data['data_range'])) {
                $data['departments'] = [];
            }
        }

        $this->assertDataRangeAllowed($data['data_range']);

        $this->assertParentRoleAllowed($data['parent_id'] ?? 0);

        return $this->model->storeBy($data);
    }

    /**
     * 当前登录用户可见的角色 id 集合；null 表示不限制（超管）
     *
     * 两部分取并集：
     *   1. 自己拥有的角色 + 其所有子角色（超管预设的层级，按 parent_id 往下）；
     *   2. 自己创建的角色（creator_id = 本人）。
     *
     * 第 2 条不能少：如果他的角色本身就是超管建的顶层角色（parent_id=0），
     * 那他新建的顶层角色不在「本人角色 + 子角色」里，建完就凭空消失。
     */
    protected function visibleRoleIds(): ?array
    {
        if ($this->getLoginUser()->isSuperAdmin()) {
            return null;
        }

        $user = $this->getLoginUser();

        $ids = $this->model->getDescendantRoleIds($user->roles->pluck('id')->toArray());

        $ids = array_merge($ids, $this->model->where('creator_id', $user->id)->pluck('id')->all());

        return array_map('intval', array_unique($ids));
    }

    /**
     * 取出可见范围内的角色
     *
     * show / update / destroy 原来都是 firstBy($id) 直接按 id 取，
     * 部门管理员传超管角色的 id 就能查看、修改、删除别人的角色。
     */
    protected function findVisibleRole($id): Roles
    {
        $role = $this->model->firstBy($id);

        $visible = $this->visibleRoleIds();

        if ($visible !== null && ! in_array((int) $role->getKey(), $visible, true)) {
            throw new FailedException('角色不存在或无权访问');
        }

        return $role;
    }

    /**
     * 写操作（改/删）取出「自己创建的」角色
     *
     * 可见 ≠ 可改：按层级能看到上级/同级建的角色（比如超管给部门A管理员
     * 预置的角色），但改它就成了"编辑超管设置的角色"。
     * 读走 findVisibleRole，写走这里。
     */
    protected function findOwnedRole($id): Roles
    {
        $role = $this->findVisibleRole($id);

        if (! $this->getLoginUser()->isSuperAdmin()
            && (int) $role->getAttribute('creator_id') !== (int) $this->getLoginUser()->id) {
            throw new FailedException('只能修改自己创建的角色');
        }

        return $role;
    }

    /**
     * 父角色必须在自己可见范围内
     *
     * 否则可以把子角色挂到超管角色下面，反过来让超管能看到/管理这个角色。
     */
    protected function assertParentRoleAllowed(mixed $parentId): void
    {
        $visible = $this->visibleRoleIds();

        if ($visible === null) {
            return;
        }

        $parentId = (int) $parentId;

        if ($parentId > 0 && ! in_array($parentId, $visible, true)) {
            throw new FailedException('无权在他人角色下创建子角色');
        }
    }

    /**
     * 数据权限上限：只有超级管理员能发「全部数据」。
     *
     * DataScope 里任一角色为全部数据就直接放开全部，部门管理员若能自建
     * 「全部数据」角色，部门隔离就失效了，所以在入口按死。
     */
    protected function assertDataRangeAllowed(int $dataRange): void
    {
        if ($dataRange === DataRange::All_Data->value() && ! $this->getLoginUser()->isSuperAdmin()) {
            throw new FailedException('无权设置全部数据权限');
        }
    }

    /**
     *
     * @param $id
     * @param Request $request
     * @return Model|null
     */
    public function show($id, Request $request)
    {
        $role = $this->findVisibleRole($id);

        if ($request->has('from') && $request->get('from') == 'parent_role') {
            $role->setAttribute('permissions', $role->permissions()->get()->toTree());
        } else {
            $role->setAttribute('permissions', $role->permissions()->get()->pluck('id'));
        }

        $role->setAttribute('departments', $role->departments()->pluck('id'));

        return $role;
    }

    /**
     *
     * @param $id
     * @param RoleRequest $request
     * @return mixed
     */
    public function update($id, RoleRequest $request)
    {
        $this->findOwnedRole($id);

        $data = $request->all();
        $data['data_range'] = (int) $data['data_range'];
        if (!DataRange::Personal_Choose->assert($data['data_range'])) {
            $data['departments'] = [];
        }

        $this->assertDataRangeAllowed($data['data_range']);

        return $this->model->updateBy($id, $data);
    }

    /**
     * @param $id
     * @return bool|null
     */
    public function destroy($id)
    {
        $this->findOwnedRole($id);

        if ($this->model->where($this->model->getParentIdColumn(), $id)->first()) {
            throw new FailedException('请先删除子角色');
        }

        return $this->model->deleteBy($id);
    }
}
