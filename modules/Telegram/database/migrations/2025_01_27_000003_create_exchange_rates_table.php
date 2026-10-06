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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 10)->comment('源币种');
            $table->string('to_currency', 10)->comment('目标币种');
            $table->decimal('rate', 20, 8)->comment('汇率');
            $table->decimal('buy_rate', 20, 8)->nullable()->comment('买入汇率（充值）');
            $table->decimal('sell_rate', 20, 8)->nullable()->comment('卖出汇率（提现）');
            $table->tinyInteger('auto_update')->default(0)->comment('是否自动更新: 0=手动, 1=自动');
            $table->string('source', 50)->nullable()->comment('汇率来源');
            $table->tinyInteger('status')->default(1)->comment('状态: 0=禁用, 1=启用');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            // 显式命名：自动生成名 = {前缀}exchange_rates_from_currency_to_currency_deleted_at_unique，
            // 长表前缀下会超过 MySQL 索引名 64 字符上限（报错 1059）。
            $table->unique(['from_currency', 'to_currency', 'deleted_at'], 'uk_exchange_rate');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
