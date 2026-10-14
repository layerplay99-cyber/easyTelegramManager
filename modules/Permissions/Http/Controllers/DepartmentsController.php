<?php

declare(strict_types=1);

namespace Modules\Permissions\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Modules\Permissions\Models\Departments;
use Modules\Permissions\Support\DataScope;
use Modules\Permissions\Support\HierarchyScope;
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
     * 层级 = creator_id 归属链 + parent_id 子树，详见 HierarchyScope：
     *   - 看得见自己建的、以及自己建的账号（递归）建的部门；
     *   - 这些部门下面的整棵子树也看得见；
     *   - 看不见上级（超管）建的部门，哪怕本人就隶属于这个部门——
     *     「被分配进去」不等于「归他管」，否则管理员一登录又看到超管的部门。
     */
    protected function visibleDepartmentIds(): ?array
    {
        return app(HierarchyScope::class)->visibleIds($this->model, $this->getLoginUser());
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
     * 写操作（改/删/启停）取出「归属链内」的部门
     *
     * 可见 ≠ 可改，可改也不等于「只能改自己建的」：
     *   - 超管建的部门：看都看不到，更不该改；
     *   - 下级建的部门：上级看得到，也归上级管。
     * 所以写权限按归属链判：creator_id ∈ {本人} ∪ {本人创建的账号，递归}。
     */
    protected function findOwnedDepartment($id): Departments
    {
        $department = $this->findVisibleDepartment($id);

        app(DataScope::class)->assertInOwnershipChain(
            $department,
            $this->getLoginUser(),
            '只能修改自己及下级创建的部门'
        );

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
