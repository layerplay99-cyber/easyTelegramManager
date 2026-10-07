<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 功能列表无代码化：核心结构
 *
 * 1) features 增加 driver / trigger，取代原先臃肿的 type/location/requestType 三个枚举。
 *    - driver  = 执行器（Telegram API / 三方请求 / 存数据 / 接收推送 / miniapp）
 *    - trigger = 触发方式（命令 / 按钮 / 回调 / webhook / 手动）
 *    旧的 type/feature/handler 字段保留但不再被新链路使用，便于存量数据平滑过渡。
 *
 * 2) feature_commands：斜杠命令元数据（命令名独立成实体，可加唯一约束）
 *    params 用声明式 schema，支持多个参数、必填、正则、以及到三方字段的映射（map_to）。
 *
 * 3) feature_data：功能运行时数据（替代 group_configs 宽表 + Cache::forever）
 *    一个功能一份、按作用域（群/机器人/用户/全局）存 json，功能参数有几个就存几个。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        // ---------- 1) features：新增 driver / trigger ----------
        Schema::table('features', function (Blueprint $table) {
            if (! Schema::hasColumn('features', 'driver')) {
                $table->string('driver', 64)->default('telegram.api')
                    ->comment('执行器 key，见 DriverRegistry');
            }
            if (! Schema::hasColumn('features', 'trigger')) {
                $table->string('trigger', 32)->default('command')
                    ->comment('触发方式 command|callback_query|webhook|manual');
            }
        });

        // ---------- 2) feature_commands ----------
        if (! Schema::hasTable('feature_commands')) {
            Schema::create('feature_commands', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('feature_id')->comment('所属功能');
                $table->string('command', 64)->comment('命令名，不含斜杠');
                $table->string('usage', 255)->nullable()->comment('用法示例');
                $table->string('description', 255)->nullable()->comment('命令描述');
                $table->string('scope', 20)->default('group')->comment('group|private 生效范围');
                $table->string('permission', 20)->default('all')->comment('all|admin 仅管理员可用');
                $table->json('params')->nullable()->comment('参数声明 schema，支持多个参数');
                $table->text('reply_template')->nullable()->comment('回复模板，支持 {{path.to.value}}');
                $table->boolean('enabled')->default(true);

                $table->creatorId();
                $table->createdAt();
                $table->updatedAt();
                $table->deletedAt();

                $table->unique('command', 'uniq_feature_command');
                $table->index('feature_id', 'idx_fc_feature');
                $table->engine = 'InnoDB';
                $table->comment('功能斜杠命令');
            });
        }

        // ---------- 3) feature_data ----------
        if (! Schema::hasTable('feature_data')) {
            Schema::create('feature_data', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('feature_id')->comment('所属功能');
                $table->string('scope_type', 20)->default('group')
                    ->comment('group|bot|user|global 作用域');
                $table->string('scope_id', 64)->default('')->comment('作用域标识：群ID/机器人ID/用户ID');
                $table->json('data')->nullable()->comment('功能数据，参数有几个存几个');
                $table->boolean('enabled')->default(true);

                $table->creatorId();
                $table->createdAt();
                $table->updatedAt();
                $table->deletedAt();

                $table->unique(['feature_id', 'scope_type', 'scope_id'], 'uniq_feature_data_scope');
                $table->index(['scope_type', 'scope_id'], 'idx_fd_scope');
                $table->engine = 'InnoDB';
                $table->comment('功能运行时数据（替代宽表+缓存）');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_data');
        Schema::dropIfExists('feature_commands');

        if (Schema::hasTable('features')) {
            Schema::table('features', function (Blueprint $table) {
                foreach (['driver', 'trigger'] as $column) {
                    if (Schema::hasColumn('features', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};