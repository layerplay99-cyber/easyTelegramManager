<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 一个机器人可绑定多个上游 + 上游按功能声明能力
 *
 * 三层关系：
 *   bot_third_configs        机器人 ↔ 上游（候选池，is_default 给无绑定上下文的调用兜底）
 *   third_config_features    上游 ↔ 功能（能力声明，只接受 needs_upstream 功能）
 *   features_binds.third_config_id（已有）  具体功能绑定落到 ①∩② 里的某一个上游
 *
 * bots.third_config_id 保留为「默认上游」镜像，由 UpstreamResolver 同步维护，
 * 这样群发等只读该列的链路完全不用改。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_third_configs')) {
            Schema::create('bot_third_configs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bot_id')->comment('机器人ID');
                $table->unsignedBigInteger('third_config_id')->comment('上游配置ID');
                $table->boolean('is_default')->default(false)->comment('默认上游');
                $table->unsignedBigInteger('tenant_id')->default(0)->comment('平台商户');
                $table->timestamps();

                $table->unique(['bot_id', 'third_config_id'], 'uk_bot_third_config');
                $table->index(['bot_id', 'is_default'], 'idx_bot_default');
                $table->index('tenant_id', 'idx_btc_tenant');
                $table->comment('机器人可绑定的上游（多对多）');
            });
        }

        if (! Schema::hasTable('third_config_features')) {
            Schema::create('third_config_features', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('third_config_id')->comment('上游配置ID');
                $table->unsignedBigInteger('feature_id')->comment('功能ID');
                $table->unsignedBigInteger('tenant_id')->default(0)->comment('平台商户');
                $table->timestamps();

                $table->unique(['third_config_id', 'feature_id'], 'uk_tcf_config_feature');
                $table->index('feature_id', 'idx_tcf_feature');
                $table->index('tenant_id', 'idx_tcf_tenant');
                $table->comment('上游声明支持的功能（官方 API 功能不允许入库）');
            });
        }

        if (! Schema::hasTable('feature_upstream_endpoints')) {
            Schema::create('feature_upstream_endpoints', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('feature_id')->comment('功能ID');
                $table->string('endpoint_code', 128)->comment('平台接口 code');
                $table->timestamps();

                $table->unique(['feature_id', 'endpoint_code'], 'uk_fue_feature_code');
                $table->index('endpoint_code', 'idx_fue_code');
                $table->comment('功能 → 它实际会调用的平台接口（由代码声明同步）');
            });
        }

        if (! Schema::hasColumn('features', 'needs_upstream')) {
            Schema::table('features', function (Blueprint $table) {
                $table->boolean('needs_upstream')->default(false)->comment('该功能是否需要交互上游；false = 官方 API / 本地，禁止绑定上游');
            });
        }

        $this->backfill();
    }

    /**
     * 存量迁移：把原来「一个机器人一个上游」和「绑定级上游」平滑写进新表。
     * 项目未上线，不做双写兼容，只做一次性回填。
     */
    private function backfill(): void
    {
        if (Schema::hasColumn('bots', 'third_config_id')) {
            foreach (DB::table('bots')->where('third_config_id', '>', 0)->get(['id', 'third_config_id']) as $bot) {
                DB::table('bot_third_configs')->updateOrInsert(
                    ['bot_id' => $bot->id, 'third_config_id' => $bot->third_config_id],
                    ['is_default' => 1, 'tenant_id' => 0, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        if (Schema::hasTable('features_binds') && Schema::hasColumn('features_binds', 'third_config_id')) {
            $query = DB::table('features_binds')
                ->where('third_config_id', '>', 0)
                ->select('third_config_id', 'feature_id')
                ->distinct();

            foreach ($query->get() as $bind) {
                DB::table('third_config_features')->updateOrInsert(
                    ['third_config_id' => $bind->third_config_id, 'feature_id' => $bind->feature_id],
                    ['tenant_id' => 0, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_upstream_endpoints');
        Schema::dropIfExists('third_config_features');
        Schema::dropIfExists('bot_third_configs');

        if (Schema::hasColumn('features', 'needs_upstream')) {
            Schema::table('features', function (Blueprint $table) {
                $table->dropColumn('needs_upstream');
            });
        }
    }
};
