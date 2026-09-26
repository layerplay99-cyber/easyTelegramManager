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
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->unique()->comment('Telegram用户ID');
            $table->string('telegram_username', 100)->nullable()->comment('Telegram用户名');
            $table->string('avatar', 500)->nullable()->comment('头像');

            // 安全设置（仅保留支付密码）
            $table->string('payment_password', 255)->nullable()->comment('支付密码');

            // 风控字段
            $table->string('register_ip', 50)->nullable()->comment('注册IP');
            $table->string('last_ip', 50)->nullable()->comment('最后操作IP');
            $table->integer('last_active_at')->default(0)->comment('最后活跃时间');

            // 状态管理
            $table->tinyInteger('status')->default(1)->comment('状态: 0=禁用, 1=正常, 2=冻结');
            $table->string('remark', 500)->nullable()->comment('备注');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            $table->index('telegram_user_id');
            $table->index('telegram_username');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
