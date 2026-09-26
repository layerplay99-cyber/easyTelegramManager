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
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('member_id')->comment('会员ID');
            $table->string('currency', 10)->default('USDT')->comment('币种: CNY, VND, USD, USDT等');
            $table->decimal('balance', 20, 8)->default(0)->comment('可用余额');
            $table->decimal('frozen_balance', 20, 8)->default(0)->comment('冻结余额');
            $table->decimal('total_recharge', 20, 8)->default(0)->comment('累计充值');
            $table->decimal('total_withdraw', 20, 8)->default(0)->comment('累计提现');
            $table->tinyInteger('status')->default(1)->comment('状态: 0=禁用, 1=正常');
            $table->string('remark', 500)->nullable()->comment('备注');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            $table->unique(['member_id', 'currency']);
            $table->index('member_id');
            $table->index('currency');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
