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
     * 给查询套上数据范围
     *
     * 岗位原来 index() 是裸 getList()，部门管理员能看到全平台岗位；
     * show/update/destroy/enable 也完全不校验，可以改别人的岗位。
     * 这里统一按 creator_id 收窄（与其它模块一致：本人 + 下级创建的数据）。
     */
    protected function scopeQuery($query): mixed
    {
        return app(DataScope::class)->apply($query, null, null, 'creator_id');
    }

    /**
     * 取出可见范围内的岗位（越权直接抛异常）
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
        return $this->model->setBeforeGetList(fn ($query) => $this->scopeQuery($query))->getList();
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
