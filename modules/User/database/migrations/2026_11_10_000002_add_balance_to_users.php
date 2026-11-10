<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 商户账号的平台侧余额（余额扣款购买套餐）。decimal(20,8) 与
            // 钱包/订单金额约定一致；调整必须走 balance_logs 台账留痕。
            $table->decimal('balance', 20, 8)->default(0)->after('expired_at');
        });

        Schema::create('balance_logs', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->comment('账号ID');
            $table->decimal('delta', 20, 8)->comment('变动金额（正=充值/调入，负=扣减）');
            $table->decimal('balance_after', 20, 8)->comment('变动后余额');
            $table->string('type', 30)->comment('类型：purchase=购套餐 adjust=管理员调账');
            $table->bigInteger('order_id')->nullable()->comment('关联订单ID');
            $table->string('remark', 500)->nullable()->comment('备注');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->index('user_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance');
        });
    }
};
