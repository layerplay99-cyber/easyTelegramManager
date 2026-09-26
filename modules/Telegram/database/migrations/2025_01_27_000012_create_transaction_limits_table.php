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
        Schema::create('transaction_limits', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('限额名称');
            $table->string('type', 30)->comment('类型: recharge=充值, withdraw=提现');
            $table->string('level', 30)->default('default')->comment('等级: default=默认, vip1, vip2等');
            $table->string('currency', 10)->comment('币种');

            // 单笔限额
            $table->decimal('min_amount', 20, 8)->default(0)->comment('单笔最小金额');
            $table->decimal('max_amount', 20, 8)->default(0)->comment('单笔最大金额');

            // 每日限额
            $table->decimal('daily_amount', 20, 8)->default(0)->comment('每日限额(0=不限)');
            $table->integer('daily_count')->default(0)->comment('每日次数限制(0=不限)');

            // 每月限额
            $table->decimal('monthly_amount', 20, 8)->default(0)->comment('每月限额(0=不限)');
            $table->integer('monthly_count')->default(0)->comment('每月次数限制(0=不限)');

            $table->tinyInteger('status')->default(1)->comment('状态: 0=禁用, 1=启用');
            $table->string('remark', 500)->nullable()->comment('备注');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            $table->index('type');
            $table->index('level');
            $table->index('currency');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_limits');
    }
};
