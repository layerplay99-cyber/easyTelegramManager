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
        Schema::create('risk_control_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('规则名称');
            $table->string('code', 50)->unique()->comment('规则编码');
            $table->string('type', 30)->comment('规则类型: recharge=充值, withdraw=提现, transfer=转账');

            // 规则条件
            $table->text('conditions')->comment('规则条件JSON');
            // 示例: {"daily_amount": 10000, "daily_count": 5, "single_amount": 5000, "ip_limit": 3}

            // 触发动作
            $table->string('action', 30)->comment('触发动作: reject=拒绝, manual_audit=人工审核, freeze=冻结账户, notify=通知');
            $table->text('action_config')->nullable()->comment('动作配置JSON');

            // 风险等级
            $table->tinyInteger('risk_level')->default(1)->comment('风险等级: 1=低, 2=中, 3=高, 4=严重');
            $table->integer('priority')->default(0)->comment('优先级(数字越大越优先)');

            $table->tinyInteger('status')->default(1)->comment('状态: 0=禁用, 1=启用');
            $table->string('remark', 500)->nullable()->comment('备注');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            $table->index('code');
            $table->index('type');
            $table->index(['status', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_control_rules');
    }
};
