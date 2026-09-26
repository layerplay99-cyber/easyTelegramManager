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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('member_id')->comment('会员ID');
            $table->string('type', 30)->comment('类型: recharge=充值通知, withdraw=提现通知, audit=审核通知, system=系统通知');
            $table->string('title', 200)->comment('标题');
            $table->text('content')->comment('内容');

            // 关联信息
            $table->string('related_type', 50)->nullable()->comment('关联类型');
            $table->bigInteger('related_id')->nullable()->comment('关联ID');

            // 发送渠道
            $table->string('channel', 30)->comment('发送渠道: telegram=飞机, sms=短信, email=邮件');
            $table->tinyInteger('send_status')->default(0)->comment('发送状态: 0=待发送, 1=已发送, 2=发送失败');
            $table->timestamp('sent_at')->nullable()->comment('发送时间');
            $table->string('error_msg', 500)->nullable()->comment('错误信息');

            // 阅读状态
            $table->tinyInteger('read_status')->default(0)->comment('阅读状态: 0=未读, 1=已读');
            $table->timestamp('read_at')->nullable()->comment('阅读时间');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            $table->index('member_id');
            $table->index('type');
            $table->index(['related_type', 'related_id']);
            $table->index('send_status');
            $table->index('read_status');
            $table->index('created_at');
            $table->index(['member_id', 'read_status']); // 查询未读消息
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
