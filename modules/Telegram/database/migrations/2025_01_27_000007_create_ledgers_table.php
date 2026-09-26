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
        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('member_id')->comment('会员ID');
            $table->bigInteger('wallet_id')->comment('钱包ID');
            $table->string('order_no', 50)->nullable()->comment('关联订单号');

            // 交易信息
            $table->string('type', 30)->comment('类型: recharge=充值, withdraw=提现, transfer=转账, refund=退款, fee=手续费, reward=奖励, deduct=扣款');
            $table->string('currency', 10)->comment('币种');
            $table->decimal('amount', 20, 8)->comment('金额(正数=收入,负数=支出)');
            $table->decimal('balance_before', 20, 8)->comment('交易前余额');
            $table->decimal('balance_after', 20, 8)->comment('交易后余额');
            $table->decimal('frozen_before', 20, 8)->default(0)->comment('交易前冻结金额');
            $table->decimal('frozen_after', 20, 8)->default(0)->comment('交易后冻结金额');

            // 关联信息
            $table->string('related_type', 50)->nullable()->comment('关联类型: RechargeOrder, WithdrawOrder等');
            $table->bigInteger('related_id')->nullable()->comment('关联ID');

            // 描述信息
            $table->string('title', 200)->comment('标题');
            $table->string('description', 500)->nullable()->comment('描述');
            $table->string('remark', 500)->nullable()->comment('备注');

            // 操作信息
            $table->bigInteger('operator_id')->default(0)->comment('操作人ID(0=系统)');
            $table->string('operator_type', 50)->default('system')->comment('操作人类型: system=系统, admin=管理员, member=会员');
            $table->string('ip', 50)->nullable()->comment('操作IP');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            $table->index('member_id');
            $table->index('wallet_id');
            $table->index('order_no');
            $table->index('type');
            $table->index(['related_type', 'related_id']);
            $table->index('created_at');
            $table->index(['member_id', 'created_at']); // 查询会员账单
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledgers');
    }
};
