<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 功能列表无代码化：三方接口 / 推送回调 / 执行日志
 *
 * 1) third_api_endpoints：把「接口」从 thirdapi_config 里拆出来独立成表。
 *    原因：同一个上游通常有多个接口（查余额 / 查订单），若只有一张表就得为每个接口
 *    重复建一条配置，功能里再硬编码路径。拆开后接口可被多个功能复用，功能只引用 endpoint_id。
 *
 * 2) feature_hooks：第三方推送入口（第三块功能）。
 *    一个 feature 对应一个 token，上游回调 POST /api/hooks/{token} 即可定位到功能，
 *    不再需要「每个业务一个写死路由」。
 *
 * 3) feature_logs：功能执行日志。原 features_logs 表有两个错误的唯一键
 *    （同一功能第二次报错就插不进去），且列名 feature_id 与模型 features_id 不一致。
 *    这里建一张干净的日志表：记录成功/失败、耗时、命令、请求ID。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('third_api_endpoints')) {
            Schema::create('third_api_endpoints', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('third_config_id')->comment('所属上游配置');
                $table->string('name', 100)->comment('接口名称');
                $table->string('method', 10)->default('GET')->comment('HTTP 方法');
                $table->string('path_template', 255)->comment('路径模板，支持 {param} 占位');
                $table->json('headers')->nullable()->comment('固定请求头');
                $table->json('query')->nullable()->comment('固定查询参数');
                $table->unsignedInteger('timeout')->default(30)->comment('超时秒数');
                $table->boolean('enabled')->default(true);
                $table->string('remark', 255)->nullable();

                $table->creatorId();
                $table->createdAt();
                $table->updatedAt();
                $table->deletedAt();

                $table->unique(['third_config_id', 'name'], 'uniq_third_endpoint');
                $table->index('third_config_id', 'idx_tep_config');
                $table->engine = 'InnoDB';
                $table->comment('三方API接口');
            });
        }

        if (! Schema::hasTable('feature_hooks')) {
            Schema::create('feature_hooks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('feature_id')->comment('所属功能');
                $table->string('token', 64)->comment('回调令牌，上游凭此路由');
                $table->string('name', 100)->nullable();
                $table->string('dedup_field', 64)->nullable()->comment('幂等去重字段，空则不开启');
                $table->boolean('enabled')->default(true);
                $table->unsignedBigInteger('last_fired_at')->default(0)->comment('Unix时间戳');

                $table->creatorId();
                $table->createdAt();
                $table->updatedAt();
                $table->deletedAt();

                $table->unique('token', 'uniq_feature_hook_token');
                $table->index('feature_id', 'idx_fh_feature');
                $table->engine = 'InnoDB';
                $table->comment('功能推送回调入口');
            });
        }

        if (! Schema::hasTable('feature_logs')) {
            Schema::create('feature_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('feature_id')->nullable();
                $table->string('command', 64)->nullable()->comment('触发的命令');
                $table->unsignedBigInteger('bot_id')->nullable();
                $table->bigInteger('chat_id')->nullable();
                $table->bigInteger('user_id')->nullable();
                $table->string('request_id', 64)->nullable()->comment('请求追踪ID');
                $table->boolean('success')->default(true);
                $table->string('level', 20)->default('info');
                $table->text('message')->nullable();
                $table->json('meta')->nullable()->comment('入参/出参/错误上下文');
                $table->unsignedInteger('duration_ms')->default(0);

                $table->createdAt();
                $table->updatedAt();

                $table->index(['feature_id', 'success'], 'idx_fl_feature');
                $table->index('request_id', 'idx_fl_request');
                $table->engine = 'InnoDB';
                $table->comment('功能执行日志');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_logs');
        Schema::dropIfExists('feature_hooks');
        Schema::dropIfExists('third_api_endpoints');
    }
};