<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 交易通知 → 群内交互按钮 的两张核心表
 *
 * 旧实现的问题（这套表就是为了解决它们）：
 *   1) /trbd 商户id 把映射写进 Cache::forever('mid', chat_id) —— 单机单商户的写法，
 *      多商户多 bot 下必然互相覆盖，重启/清缓存即丢，且无法审计。
 *   2) 按钮的唯一 id 只存 Redis、没有归属校验 —— 只要猜到/拿到就能在别的群点。
 *   3) 谁能点只有「管理员」一种策略，且判定松散。
 *
 * merchant_routes      商户号 → (机器人, 群, 上游) 的路由表，唯一约束保证一个商户只绑一个群
 * interactive_sessions 一次交互的会话：按钮只带 code，所有业务数据留在服务端
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('merchant_routes')) {
            Schema::create('merchant_routes', function (Blueprint $table) {
                $table->id();
                $table->string('merchant_id', 64)->comment('商户号，上游回调里带的那个');
                $table->unsignedBigInteger('bot_id')->default(0)->comment('用哪个机器人发消息');
                $table->string('chat_id', 64)->comment('发到哪个群');
                $table->unsignedBigInteger('third_config_id')->nullable()->comment('该商户走哪个上游，空则回落到机器人/功能级');
                $table->unsignedBigInteger('feature_id')->nullable()->comment('由哪个绑定功能写入，便于审计');
                $table->boolean('status')->default(true)->comment('1=启用');
                $table->string('remark', 255)->nullable();

                $table->creatorId();
                $table->createdAt();
                $table->updatedAt();
                $table->deletedAt();

                $table->unique('merchant_id', 'uniq_mr_merchant');
                $table->index('chat_id', 'idx_mr_chat');
                $table->index('bot_id', 'idx_mr_bot');
                $table->engine = 'InnoDB';
                $table->comment('商户号路由（商户→机器人+群+上游）');
            });
        }

        if (! Schema::hasTable('interactive_sessions')) {
            Schema::create('interactive_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('code', 32)->comment('按钮携带的短句柄，随机不可枚举');
                $table->string('business_type', 32)->default('trade')->comment('业务类型，预留给多业务复用');
                $table->unsignedBigInteger('feature_id')->nullable()->comment('发起会话的功能');
                $table->unsignedBigInteger('bot_id')->default(0);
                $table->string('chat_id', 64);
                $table->string('message_id', 64)->nullable()->comment('带按钮的那条消息，用于事后撤按钮/编辑');
                $table->string('merchant_id', 64)->nullable()->index('idx_is_merchant');
                $table->unsignedBigInteger('third_config_id')->nullable()->comment('点完调哪个上游');
                $table->unsignedSmallInteger('step')->default(0)->comment('多步交互当前步');
                $table->string('pending_act', 32)->nullable()->comment('二次确认时暂存的动作');
                $table->string('status', 16)->default('pending')->comment('pending/processing/done/failed/cancelled/expired');
                $table->json('payload')->nullable()->comment('上游回调原文');
                $table->json('buttons')->nullable()->comment('按钮布局快照，避免中途改配置导致按钮错乱');
                $table->json('allowed')->nullable()->comment('权限快照：当时谁被允许点');
                $table->string('dedup_key', 128)->nullable()->comment('上游单据指纹，防重复建会话');
                $table->unsignedBigInteger('expires_at')->default(0)->comment('Unix 秒，过期按钮点了无效');
                $table->unsignedSmallInteger('attempts')->default(0)->comment('已提交次数，失败可重试');
                $table->unsignedBigInteger('operator_user_id')->nullable()->comment('谁点的');
                $table->string('operator_name', 100)->nullable();
                $table->json('result')->nullable()->comment('上游返回与执行结果');

                $table->creatorId();
                $table->createdAt();
                $table->updatedAt();
                $table->deletedAt();

                $table->unique('code', 'uniq_is_code');
                $table->index('dedup_key', 'idx_is_dedup');
                $table->index(['status', 'expires_at'], 'idx_is_status');
                $table->engine = 'InnoDB';
                $table->comment('交互会话（按钮携带 code，业务数据留服务端）');
            });
        }

        // 回调验签密钥（可选）：配了才验签，不配则维持原行为，兼容已有 hook
        if (Schema::hasTable('feature_hooks') && ! Schema::hasColumn('feature_hooks', 'secret')) {
            Schema::table('feature_hooks', function (Blueprint $table) {
                $table->string('secret', 128)->nullable()->comment('回调验签密钥，留空=不验签');
                $table->string('sign_field', 32)->nullable()->default('sign')->comment('签名字段名');
                $table->string('sign_algo', 16)->nullable()->default('hmac_sha256')->comment('hmac_sha256 / md5');
                $table->boolean('check_timestamp')->default(false)->comment('是否校验 timestamp 防重放');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('interactive_sessions');
        Schema::dropIfExists('merchant_routes');

        if (Schema::hasTable('feature_hooks')) {
            Schema::table('feature_hooks', function (Blueprint $table) {
                foreach (['secret', 'sign_field', 'sign_algo', 'check_timestamp'] as $col) {
                    if (Schema::hasColumn('feature_hooks', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
