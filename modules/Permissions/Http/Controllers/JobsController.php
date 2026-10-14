<?php

namespace Modules\Permissions\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Modules\Permissions\Models\Jobs;
use Modules\Permissions\Support\DataScope;
use Illuminate\Http\Request;

class JobsController extends Controller
{
    public function __construct(
        protected readonly Jobs $model
    ) {
    }

    /**
     * 岗位是「组织字典」而不是部门私有数据
     *
     * 之前按 creator_id 收窄列表，结果部门A管理员列表直接空了——
     * 超管预置的岗位（creator_id=1）看不到，自己又一个都没建，
     * 导致给他创建的账号分配不了岗位。
     *
     * 所以这里区分读和写：
     *   - 读（index / show）：全员可见，岗位是共享字典；
     *   - 写（update / destroy / enable）：只能改自己创建的，超管可改全部。
     */
    protected function findVisibleJob($id): Jobs
    {
        $job = $this->model->findOrFail($id);

        app(DataScope::class)->assertVisible($job);

        return $job;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request): mixed
    {
        return $this->model->getList();
    }

    public function store(Request $request)
    {
        // creator_id 在 Jobs 的 fillable 里，storeBy() 会按登录用户自动填
        return $this->model->storeBy($request->all());
    }

    public function show($id)
    {
        return $this->model->firstBy($id);
    }

    public function update($id, Request $request)
    {
        $this->findVisibleJob($id);

        return $this->model->updateBy($id, $request->all());
    }

    public function destroy($id)
    {
        $this->findVisibleJob($id);

        return $this->model->deleteBy($id);
    }

    public function enable($id)
    {
        $this->findVisibleJob($id);

        return $this->model->toggleBy($id);
    }
}
