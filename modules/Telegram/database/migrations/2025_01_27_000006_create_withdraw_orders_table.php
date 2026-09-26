<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('withdraw_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 50)->unique()->comment('订单号');
            $table->bigInteger('member_id')->comment('会员ID');
            $table->bigInteger('channel_id')->comment('支付通道ID');

            // 金额信息
            $table->string('currency', 10)->comment('币种');
            $table->decimal('amount', 20, 8)->comment('提现金额');
            $table->decimal('fee', 20, 8)->default(0)->comment('手续费');
            $table->decimal('actual_amount', 20, 8)->comment('实际到账金额');
            $table->decimal('exchange_rate', 20, 8)->default(1)->comment('汇率');

            // 收款信息
            $table->string('withdraw_method', 50)->comment('提现方式: bank=银行卡, alipay=支付宝, usdt=USDT等');
            $table->text('withdraw_info')->comment('提现信息JSON: {bank_name, account_no, account_name等}');
            $table->string('third_order_no', 100)->nullable()->comment('第三方订单号');

            // 状态管理（简化，去掉审核流程）
            $table->tinyInteger('status')->default(0)->comment('状态: 0=待处理, 1=处理中, 2=已完成, 3=已取消, 4=失败');
            $table->timestamp('processed_at')->nullable()->comment('处理时间');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');

            // 安全防护
            $table->string('request_ip', 50)->nullable()->comment('请求IP');
            $table->string('request_sign', 255)->nullable()->comment('请求签名');
            $table->string('nonce', 50)->nullable()->comment('随机数(防重放)');
            $table->bigInteger('timestamp')->nullable()->comment('时间戳(防重放)');

            $table->string('remark', 500)->nullable()->comment('备注');
            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            $table->index('order_no');
            $table->index('member_id');
            $table->index('channel_id');
            $table->index('third_order_no');
            $table->index('status');
            $table->index('created_at');
            $table->index(['nonce', 'timestamp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdraw_orders');
    }
};
