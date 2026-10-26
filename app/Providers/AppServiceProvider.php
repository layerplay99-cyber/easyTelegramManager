<?php

namespace App\Providers;


use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // 后台接口响应统一成一层 {code, message, data}。
        //
        // 框架默认对「来自后台」的请求无脑再包一层，控制器自己返回信封时就变成两层，
        // 前端只能靠猜剥几层（猜错的表现就是下拉为空这类静默故障）。
        // 换成我们自己的监听器：已是信封就不再包。见 App\Listeners\DashboardResponseListener。
        //
        // 必须在 register() 里覆盖：所有 provider 的 register() 都跑完之后才进入 boot()，
        // 而框架的 CatchAdminServiceProvider 是在 boot() 里读这个配置来注册监听器的。
        config([
            'catch.response.request_handled_listener' => \App\Listeners\DashboardResponseListener::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->protectCreatorIdOnUpdate();
        //$this->listenSql();
    }

    /**
     * 更新时保留数据归属（creator_id），不让操作人把数据"改到自己名下"
     *
     * 框架 BaseOperate::filterData() 里有一段：
     *     if ($this->isFillCreatorId && in_array('creator_id', $this->getFillable())) {
     *         $data['creator_id'] = Auth::guard(getGuardName())->id();
     *     }
     * 而 updateBy() 走的也是 filterData()，于是「谁改一次，数据就归谁」——
     * 超级管理员帮人改一条机器人，这条机器人就从创建者名下消失了
     * （DataScope 按 creator_id 归属链过滤，创建者再也查不到）。
     *
     * 约定：超管/上级可以改任何数据的内容，但不能顺手改走归属。
     * 归属要变更得走明确的「转移/分配」入口，而不是编辑保存的副作用。
     *
     * 只拦 updating（模型 save），不拦 creating —— 新建时本来就该记当前操作人。
     * 原值为 0/null（无主数据）时不拦，允许把它认领到当前用户名下。
     */
    private function protectCreatorIdOnUpdate(): void
    {
        Event::listen('eloquent.updating: *', function (string $event, array $models): void {
            foreach ($models as $model) {
                if (! method_exists($model, 'getOriginal') || ! $model->isDirty('creator_id')) {
                    continue;
                }

                $original = $model->getOriginal('creator_id');

                if ($original === null || (int) $original === 0) {
                    continue;
                }

                $model->setAttribute('creator_id', $original);
            }
        });
    }

    private function listenSql()
    {
        DB::listen(function ($query) {
            $sql = $query->sql;
            $bindings = $query->bindings;
            $time = $query->time;
            // 你也可以将 SQL 语句记录到日志中
            Log::info($sql, $bindings);
            Log::info('Time: '.$time.'ms');
        });
    }
}
