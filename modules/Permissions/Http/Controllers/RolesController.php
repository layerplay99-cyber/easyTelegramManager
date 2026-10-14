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

        $userRoleIds = $this->getLoginUser()->roles->pluck('id')->toArray();

        $allRoleIds = $this->model->getDescendantRoleIds($userRoleIds);

        return $this->model->setBeforeGetList(function ($query) use ($allRoleIds, $userRoleIds) {
            $query->whereIn('id', $allRoleIds)
                ->with(['permissions' => function ($query) use ($userRoleIds) {
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

        return $this->model->storeBy($data);
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
        $role = $this->model->firstBy($id);

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
        if ($this->model->where($this->model->getParentIdColumn(), $id)->first()) {
            throw new FailedException('请先删除子角色');
        }

        return $this->model->deleteBy($id);
    }
}
