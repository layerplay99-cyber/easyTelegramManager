<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 回调路由表：商户ID（或其它业务键）→ 机器人 + 群
 *
 * 背景：上游回调 POST /api/hooks/{token} 只带一个 token，报文里带商户ID。
 * 单机器人时代「商户ID → chat_id」就够了（反正只有一个机器人在发）；
 * 多机器人时必须同时确定「用哪个机器人的 token 发」+「发到哪个群」，
 * 否则会出现 A 的业务回调被 B 的机器人发出去、甚至发到 B 的群里。
 *
 * 因此：
 *   1. feature_hooks 增加 bot_id（该回调默认用哪个机器人）与 route_field
 *      （从报文里取哪个字段做路由键，如 out_merchant_id）；
 *   2. 新增 feature_hook_routes 做逐条覆盖：路由键值 → 机器人 + 群。
 *
 * 兼容：单机器人场景下只填默认机器人 + 路由表每行选同一个机器人，
 * 行为与旧的「商户ID → chat_id」完全一致。
 *
 * 同时给 bots.url_token 补唯一索引（webhook 靠它识别机器人，重复就会串）。
 * 若库里已经存在重复值，这里只告警不加索引，避免迁移直接失败——
 * 需要先手工改掉重复值再重跑本迁移。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('feature_hook_routes')) {
            Schema::create('feature_hook_routes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hook_id')->comment('所属回调入口 feature_hooks.id');
                $table->string('route_value', 128)->comment('路由键值，如商户ID out_merchant_id 的值');
                $table->unsignedBigInteger('bot_id')->comment('命中后用哪个机器人发送');
                $table->string('chat_id', 64)->comment('命中后发到哪个群');
                $table->string('remark', 255)->nullable()->comment('备注，方便后台认人');
                $table->boolean('enabled')->default(true)->comment('可单独停用某个商户');
                $table->unsignedBigInteger('creator_id')->nullable();
                // 软删除：CatchModel 的 delete() 只置 deleted_at，查询默认带 deleted_at = 0
                $table->deletedAt();
                $table->timestamps();

                $table->unique(['hook_id', 'route_value'], 'uniq_hook_route');
                $table->index('hook_id', 'idx_hook_route_hook');
                $table->index('bot_id', 'idx_hook_route_bot');
            });
        }

        if (Schema::hasTable('feature_hooks')) {
            Schema::table('feature_hooks', function (Blueprint $table) {
                if (! Schema::hasColumn('feature_hooks', 'bot_id')) {
                    $table->unsignedBigInteger('bot_id')->nullable()->after('feature_id')
                        ->comment('该回调默认用哪个机器人发送');
                }

                if (! Schema::hasColumn('feature_hooks', 'route_field')) {
                    $table->string('route_field', 128)->nullable()->after('bot_id')
                        ->comment('从回调报文里取哪个字段做路由键，如 out_merchant_id / data.merchant_id');
                }

                if (! Schema::hasColumn('feature_hooks', 'default_chat_id')) {
                    $table->string('default_chat_id', 64)->nullable()->after('route_field')
                        ->comment('未命中路由表时的兜底群；留空则用功能配置里的 target_chat_ids');
                }
            });
        }

        $this->backfillHookBotId();
        $this->uniqueUrlToken();
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_hook_routes');

        if (Schema::hasTable('feature_hooks')) {
            Schema::table('feature_hooks', function (Blueprint $table) {
                foreach (['bot_id', 'route_field', 'default_chat_id'] as $column) {
                    if (Schema::hasColumn('feature_hooks', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('bots') && $this->hasIndex('bots', 'uniq_bots_url_token')) {
            Schema::table('bots', function (Blueprint $table) {
                $table->dropUnique('uniq_bots_url_token');
            });
        }
    }

    /**
     * 回填已有回调入口的默认机器人
     *
     * 原来取「该功能绑定的第一个机器人」，老数据的 feature_hooks 没有 bot_id。
     * 这里只回填「该功能只绑定了一个机器人」这种无歧义的情况，
     * 绑了多个的留空——留空后回调会明确报 422 让人来配，好过继续猜。
     */
    protected function backfillHookBotId(): void
    {
        if (! Schema::hasTable('feature_hooks') || ! Schema::hasTable('features_binds')) {
            return;
        }

        foreach (DB::table('feature_hooks')->whereNull('bot_id')->get(['id', 'feature_id']) as $hook) {
            $botIds = DB::table('features_binds')
                ->where('feature_id', $hook->feature_id)
                ->where('enabled', 1)
                ->distinct()
                ->pluck('bot_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (count($botIds) === 1) {
                DB::table('feature_hooks')->where('id', $hook->id)->update(['bot_id' => $botIds[0]]);
            }
        }
    }

    /**
     * bots.url_token 唯一索引
     */
    protected function uniqueUrlToken(): void
    {
        if (! Schema::hasTable('bots') || $this->hasIndex('bots', 'uniq_bots_url_token')) {
            return;
        }

        $duplicates = DB::table('bots')
            ->select('url_token', DB::raw('COUNT(*) as c'))
            ->groupBy('url_token')
            ->having('c', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $tokens = $duplicates->pluck('url_token')->implode(', ');

            // 先建不了索引：重复值会让 ALTER 直接失败，整个迁移回滚。
            // 但不加索引不影响功能隔离（控制器仍按 url_token 查，只是可能查到错的人），
            // 所以这里只提示，让运维先手工改重复值再重跑。
            logger()->warning("bots.url_token 存在重复值，未加唯一索引，请先修正：{$tokens}");

            return;
        }

        Schema::table('bots', function (Blueprint $table) {
            $table->unique('url_token', 'uniq_bots_url_token');
        });
    }

    protected function hasIndex(string $table, string $index): bool
    {
        $rows = DB::select('SHOW INDEX FROM ' . DB::getTablePrefix() . $table);

        foreach ($rows as $row) {
            if (($row->Key_name ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};
