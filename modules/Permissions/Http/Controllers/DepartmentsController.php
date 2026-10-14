<?php

declare(strict_types=1);

namespace Modules\Permissions\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Modules\Permissions\Models\Departments;
use Illuminate\Http\Request;

class DepartmentsController extends Controller
{
    public function __construct(
        protected readonly Departments $model
    ) {
    }

    /**
     * @return mixed
     */
    public function index(): mixed
    {
        if ($this->getLoginUser()->isSuperAdmin()) {

            return $this->model->getList();
        }

        $visibleDepartmentIds = $this->visibleDepartmentIds();

        if (empty($visibleDepartmentIds)) {
            return [];
        }

        $this->model->setBeforeGetList(function ($query) use ($visibleDepartmentIds) {
            return $query->whereIn('id', $visibleDepartmentIds);
        });
        return $this->model->getList();
    }

    /**
     * 当前登录用户可见的部门 id 集合；null 表示不限制（超管）
     *
     * 两部分取并集：
     *   1. 本部门 + 其所有子部门（按 parent_id 往下）；
     *   2. 自己创建的部门（creator_id = 本人）——否则他用 parent_id=0
     *      新建的部门不在本部门子树里，建完就看不见。
     */
    protected function visibleDepartmentIds(): ?array
    {
        if ($this->getLoginUser()->isSuperAdmin()) {
            return null;
        }

        $user = $this->getLoginUser();

        $departmentId = (int) $user->department_id;

        $ids = $departmentId
            ? array_merge($this->model->findFollowDepartments($departmentId), [$departmentId])
            : [];

        $ids = array_merge($ids, $this->model->where('creator_id', $user->id)->pluck('id')->all());

        return array_map('intval', array_unique($ids));
    }

    /**
     * 取出可见范围内的部门
     *
     * show / update / destroy / enable 原来都是直接按 id 操作，
     * 部门管理员传超管部门的 id 就能查看、修改、删除、启停别人的部门。
     */
    protected function findVisibleDepartment($id): Departments
    {
        $department = $this->model->firstBy($id);

        $visible = $this->visibleDepartmentIds();

        if ($visible !== null && ! in_array((int) $department->getKey(), $visible, true)) {
            throw new FailedException('部门不存在或无权访问');
        }

        return $department;
    }

    /**
     * 写操作（改/删/启停）取出「自己创建的」部门
     *
     * 可见 ≠ 可改：本部门可能是超管建好再把账号丢进去的，按层级能看到，
     * 但改它就是"编辑超管设置的部门"。读走 findVisibleDepartment，写走这里。
     * 需要在自己部门下细分（如客服组/财务组）用 store，parent_id 允许落在可见范围内。
     */
    protected function findOwnedDepartment($id): Departments
    {
        $department = $this->findVisibleDepartment($id);

        if (! $this->getLoginUser()->isSuperAdmin()
            && (int) $department->getAttribute('creator_id') !== (int) $this->getLoginUser()->id) {
            throw new FailedException('只能修改自己创建的部门');
        }

        return $department;
    }

    public function store(Request $request)
    {
        $data = $request->all();

        $visible = $this->visibleDepartmentIds();

        // 父部门必须在自己可见范围内，否则能把子部门挂到超管部门下
        if ($visible !== null) {
            $parentId = (int) ($data['parent_id'] ?? 0);

            if ($parentId > 0 && ! in_array($parentId, $visible, true)) {
                throw new FailedException('无权在他人部门下创建子部门');
            }
        }

        return $this->model->storeBy($data);
    }

    public function show($id)
    {
        return $this->findVisibleDepartment($id);
    }

    public function update($id, Request $request)
    {
        $this->findOwnedDepartment($id);

        return $this->model->updateBy($id, $request->all());
    }

    public function destroy($id)
    {
        $this->findOwnedDepartment($id);

        return $this->model->deleteBy($id);
    }

    public function enable($id)
    {
        $this->findOwnedDepartment($id);

        return $this->model->toggleBy($id);
    }
}
