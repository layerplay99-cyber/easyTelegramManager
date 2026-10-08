<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 平台化重构：功能与上游彻底解耦
 *
 * 目标：功能（绑定商户 / 查询余额…）是平台标准能力，全局唯一一份；
 *每个机器人只绑定自己的上游实例。不同用户执行同一个命令，
 * 行为完全一致，只是请求的上游地址/token 不同。
 *
 * 1) bots 增加 third_config_id：该机器人用哪个上游（上游本身归属用户，靠 creator_id + DataRange 隔离）
 * 2) third_api_endpoints 改为平台维护的「接口规范」：
 *    - 去掉 third_config_id 外键（接口不再属于某个上游，而是平台标准）
 *    - 新增 code（唯一标识，功能按 code 引用）
 *    - 新增 params_schema / response_schema（统一入参与出参，接入标准）
 *
 * 直接重构，不做兼容过渡：features.config 里旧的三方id 由数据清理命令重置。
 */
return new class () extends Migration {
    public function up(): void
    {
        // ---------- 1) bots 挂上游 ----------
        if (Schema::hasTable('bots') && ! Schema::hasColumn('bots', 'third_config_id')) {
            Schema::table('bots', function (Blueprint $table) {
                $table->unsignedBigInteger('third_config_id')->nullable()
                    ->comment('该机器人使用的上游配置（thirdapi_config.id）');
            });
        }

        if (! Schema::hasTable('third_api_endpoints')) {
            return;
        }

        Schema::table('third_api_endpoints', function (Blueprint $table) {
            // 接口不再属于某个上游 → 去掉外键
            if (Schema::hasColumn('third_api_endpoints', 'third_config_id')) {
                $table->dropColumn('third_config_id');
            }

            // 平台统一标识：功能按 code 引用接口
            if (! Schema::hasColumn('third_api_endpoints', 'code')) {
                $table->string('code', 64)->nullable()->comment('平台接口标识，如 merchant.balance');
            }

            // 接入标准：统一入参/ 出参
            if (! Schema::hasColumn('third_api_endpoints', 'params_schema')) {
                $table->json('params_schema')->nullable()
                    ->comment('入参规范：[{name,required,desc,map_to}]');
            }

            if (! Schema::hasColumn('third_api_endpoints', 'response_schema')) {
                $table->json('response_schema')->nullable()
                    ->comment('出参规范：{字段名: 说明}');
            }
        });

        // code 唯一索引（历史数据可能为空，需先填充再设唯一）
        DB::table('third_api_endpoints')
            ->whereNull('code')
            ->orWhere('code', '')
            ->update(['code' => DB::raw("CONCAT('legacy_', id)")]);

        Schema::table('third_api_endpoints', function (Blueprint $table) {
            $table->unique('code', 'uniq_third_endpoint_code');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('bots') && Schema::hasColumn('bots', 'third_config_id')) {
            Schema::table('bots', function (Blueprint $table) {
                $table->dropColumn('third_config_id');
            });
        }

        if (Schema::hasTable('third_api_endpoints')) {
            Schema::table('third_api_endpoints', function (Blueprint $table) {
                foreach (['code', 'params_schema', 'response_schema'] as $column) {
                    if (Schema::hasColumn('third_api_endpoints', $column)) {
                        $table->dropColumn($column);
                    }
                }

                if (! Schema::hasColumn('third_api_endpoints', 'third_config_id')) {
                    $table->unsignedBigInteger('third_config_id')->nullable();
                }
            });
        }
    }
};