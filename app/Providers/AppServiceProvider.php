<?php

namespace App\Providers;


use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        //$this->listenSql();
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
