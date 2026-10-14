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
        $departmentId = $this->getLoginUser()->department_id;
        if (! $departmentId) {
            return [];
        }
        $followDepartmentIds = $this->model->findFollowDepartments($departmentId);
        $followDepartmentIds[] = $departmentId;
        $this->model->setBeforeGetList(function ($query) use ($followDepartmentIds) {
            return $query->whereIn('id', $followDepartmentIds);
        });
        return $this->model->getList();
    }

    /**
     * 当前登录用户可见的部门 id 集合；null 表示不限制（超管）
     *
     * 与 index() 同一套规则：本部门 + 其所有子部门。
     */
    protected function visibleDepartmentIds(): ?array
    {
        if ($this->getLoginUser()->isSuperAdmin()) {
            return null;
        }

        $departmentId = (int) $this->getLoginUser()->department_id;

        if (! $departmentId) {
            return [];
        }

        $ids = $this->model->findFollowDepartments($departmentId);
        $ids[] = $departmentId;

        return array_map('intval', $ids);
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
        $this->findVisibleDepartment($id);

        return $this->model->updateBy($id, $request->all());
    }

    public function destroy($id)
    {
        $this->findVisibleDepartment($id);

        return $this->model->deleteBy($id);
    }

    public function enable($id)
    {
        $this->findVisibleDepartment($id);

        return $this->model->toggleBy($id);
    }
}
