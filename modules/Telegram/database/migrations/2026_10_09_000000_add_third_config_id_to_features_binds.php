<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上游配置下沉到「功能绑定」级别
 *
 * 业务需求：同一个功能可被多个实体（机器人 / 真人客服）绑定，
 * 且每个（实体, 功能）组合可以指定各自的上游（third_config_id）。
 * 例如 机器人W 的功能1 用上游B，机器人V 的功能1 用上游C。
 *
 * 因此上游不再是机器人级（bots.third_config_id），而是绑定记录级：
 *   - features_binds 增加 third_config_id（可为空，空时回退到 robots/bot 级默认）
 *   - chat_id 为 NULL 的绑定视为「实体级默认」，对任意群生效；
 *     chat_id 有值的绑定为「该群覆盖」。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('features_binds') && ! Schema::hasColumn('features_binds', 'third_config_id')) {
            Schema::table('features_binds', function (Blueprint $table) {
                $table->unsignedBigInteger('third_config_id')->nullable()
                    ->after('feature_id')
                    ->comment('该(实体,功能)绑定使用的上游配置；为空时回退到实体级默认');
                $table->index(['bot_id', 'chat_id', 'feature_id'], 'idx_features_binds_entity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('features_binds') && Schema::hasColumn('features_binds', 'third_config_id')) {
            Schema::table('features_binds', function (Blueprint $table) {
                $table->dropIndex('idx_features_binds_entity');
                $table->dropColumn('third_config_id');
            });
        }
    }
};
