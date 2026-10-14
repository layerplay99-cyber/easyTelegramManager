<?php

namespace Modules\Permissions\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
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
     * 岗位没有 parent_id，层级只有 creator_id 这一维：归属链
     *
     * 本人 + 本人创建的账号（递归）建的岗位可见。
     * 上级看得到下级建的，看不到上级建的——超管预置的岗位对管理员不可见，
     * 他要给自己的账号分配岗位就自己建。
     */
    public function index(Request $request): mixed
    {
        return $this->model->setBeforeGetList(function ($query) {
            // 最后 false：不看「无主」岗位，系统预置的也不能兜底放给所有管理员
            return app(DataScope::class)->applyOwnership($query, $this->getLoginUser(), null, false);
        })->getList();
    }

    public function store(Request $request)
    {
        // creator_id 在 Jobs 的 fillable 里，storeBy() 会按登录用户自动填
        return $this->model->storeBy($request->all());
    }

    public function show($id)
    {
        return $this->findVisibleJob($id);
    }

    public function update($id, Request $request)
    {
        $this->findOwnedJob($id);

        return $this->model->updateBy($id, $request->all());
    }

    public function destroy($id)
    {
        $this->findOwnedJob($id);

        return $this->model->deleteBy($id);
    }

    public function enable($id)
    {
        $this->findOwnedJob($id);

        return $this->model->toggleBy($id);
    }

    /**
     * 读：落在归属链内（无主岗位同样不放行）
     */
    protected function findVisibleJob($id): Jobs
    {
        $job = $this->model->firstBy($id);

        if (! app(DataScope::class)->inOwnershipChain($job, $this->getLoginUser(), false)) {
            throw new FailedException('岗位不存在或无权访问');
        }

        return $job;
    }

    /**
     * 写：只能改归属链内的（本人 + 下级建的），超管的岗位动不了
     */
    protected function findOwnedJob($id): Jobs
    {
        $job = $this->findVisibleJob($id);

        app(DataScope::class)->assertInOwnershipChain(
            $job,
            $this->getLoginUser(),
            '只能修改自己及下级创建的岗位'
        );

        return $job;
    }
}
