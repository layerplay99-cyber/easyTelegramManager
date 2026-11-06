<?php

namespace App\Providers;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Modules\Permissions\Support\MerchantContext;
use Modules\Permissions\Support\Tenancy;

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
        $this->assignTenantIdOnCreate();
        $this->protectTenantIdOnUpdate();
        //$this->listenSql();
    }

    /**
     * 新建记录时统一写 tenant_id
     *
     * 走全局 creating 事件而不是改 40 多张表的模型 $fillable：
     * BaseOperate::filterData() 会丢掉不在 fillable / form 里的字段，
     * 逐个模型补 fillable 只要漏一个，那条数据就静默变成 0（平台共享）。
     * 这里按 Tenancy::TABLES 白名单统一注入，super admin 的「归属商户」由
     * MerchantContext::forCreate() 从原始 request 读取，同样不受 filterData 影响。
     *
     * 无登录态（CLI / 队列 / webhook）一律写 0，与改造前的行为一致。
     */
    private function assignTenantIdOnCreate(): void
    {
        Event::listen('eloquent.creating: *', function (string $event, array $models): void {
            foreach ($models as $model) {
                if (! $model instanceof Model || ! Tenancy::supports($model)) {
                    continue;
                }

                $current = $model->getAttribute(Tenancy::COLUMN);

                // 调用方已显式指定（forceFill / create 带值）时以它为准
                if ($current !== null && (int) $current !== 0) {
                    continue;
                }

                $model->setAttribute(Tenancy::COLUMN, MerchantContext::forCreate());
            }
        });
    }

    /**
     * 编辑时保护归属，只有平台超管能转移
     *
     * 与 creator_id 的保护同理：不能因为一次编辑就把数据改到别的商户名下。
     */
    private function protectTenantIdOnUpdate(): void
    {
        Event::listen('eloquent.updating: *', function (string $event, array $models): void {
            foreach ($models as $model) {
                if (! $model instanceof Model || ! Tenancy::supports($model)) {
                    continue;
                }

                $transfer = MerchantContext::requestedTransfer();

                if ($transfer !== null) {
                    $model->setAttribute(Tenancy::COLUMN, $transfer);

                    continue;
                }

                if ($model->isDirty(Tenancy::COLUMN)) {
                    $model->setAttribute(Tenancy::COLUMN, $model->getOriginal(Tenancy::COLUMN) ?? 0);
                }
            }
        });
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
