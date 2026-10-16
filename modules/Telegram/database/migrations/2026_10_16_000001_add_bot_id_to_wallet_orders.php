<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 钱包订单记录「是哪个机器人的业务」
     *
     * 充值 / 提现的通知（到账、完成）要发回用户当时交互的那个机器人：
     * 多 bot 多商户下没有全局 bot 可用，只能从订单上取。
     * 建单时由调用方传入（功能驱动有 $context->bot，MiniApp 按 feature 反查），
     * 历史数据为 0，取不到机器人时通知会记日志跳过，不再静默用别人的 bot。
     */
    public function up(): void
    {
        if (! Schema::hasTable('recharge_orders')) {
            return;
        }

        foreach (['recharge_orders', 'withdraw_orders'] as $table) {
            if (! Schema::hasColumn($table, 'bot_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->unsignedBigInteger('bot_id')->default(0)->after('member_id')
                        ->comment('发起该订单的机器人 id，0=未知');

                    $table->index('bot_id', 'idx_wallet_order_bot');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['recharge_orders', 'withdraw_orders'] as $table) {
            if (Schema::hasColumn($table, 'bot_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropIndex('idx_wallet_order_bot');
                    $table->dropColumn('bot_id');
                });
            }
        }
    }
};
