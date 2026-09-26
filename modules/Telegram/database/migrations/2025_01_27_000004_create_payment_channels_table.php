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
        Schema::create('payment_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('通道名称');
            $table->string('code', 50)->unique()->comment('通道编码');
            $table->string('type', 20)->comment('类型: recharge=充值, withdraw=提现, both=双向');
            $table->string('currency', 10)->comment('支持币种');
            $table->string('method', 50)->comment('支付方式: bank=银行卡, alipay=支付宝, usdt=USDT等');

            // 通道API配置（独立字段）
            $table->string('submit_api', 500)->nullable()->comment('提单API地址');
            $table->string('query_api', 500)->nullable()->comment('查询API地址');
            $table->string('merchant_id', 100)->nullable()->comment('商户号');
            $table->string('secret_key', 255)->nullable()->comment('密钥');
            $table->text('extra_config')->nullable()->comment('额外配置JSON（可选）');

            // 手续费配置
            $table->decimal('fee_rate', 10, 4)->default(0)->comment('手续费率(%)');
            $table->decimal('fixed_fee', 20, 8)->default(0)->comment('固定手续费');

            // 限额配置
            $table->decimal('min_amount', 20, 8)->default(0)->comment('最小金额');
            $table->decimal('max_amount', 20, 8)->default(0)->comment('最大金额');
            $table->decimal('daily_limit', 20, 8)->default(0)->comment('每日限额(0=不限)');
            $table->integer('daily_count_limit')->default(0)->comment('每日次数限制(0=不限)');

            // 状态管理
            $table->tinyInteger('status')->default(1)->comment('状态: 0=禁用, 1=启用, 2=维护中');
            $table->integer('priority')->default(0)->comment('优先级(数字越大越优先)');
            $table->string('remark', 500)->nullable()->comment('备注');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            $table->index('code');
            $table->index('type');
            $table->index('currency');
            $table->index('method');
            $table->index(['status', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_channels');
    }
};
