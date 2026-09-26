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
        Schema::create('risk_control_logs', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('member_id')->comment('会员ID');
            $table->bigInteger('rule_id')->comment('触发的规则ID');
            $table->string('order_no', 50)->nullable()->comment('关联订单号');

            // 触发信息
            $table->string('type', 30)->comment('类型: recharge=充值, withdraw=提现');
            $table->tinyInteger('risk_level')->comment('风险等级');
            $table->text('trigger_data')->comment('触发数据JSON');
            $table->string('action', 30)->comment('执行动作');
            $table->text('action_result')->nullable()->comment('动作执行结果');

            // 处理信息
            $table->tinyInteger('status')->default(0)->comment('状态: 0=待处理, 1=已处理, 2=已忽略');
            $table->bigInteger('handler_id')->nullable()->comment('处理人ID');
            $table->timestamp('handled_at')->nullable()->comment('处理时间');
            $table->string('handle_remark', 500)->nullable()->comment('处理备注');

            $table->string('ip', 50)->nullable()->comment('操作IP');
            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            $table->index('member_id');
            $table->index('rule_id');
            $table->index('order_no');
            $table->index('type');
            $table->index('risk_level');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_control_logs');
    }
};
