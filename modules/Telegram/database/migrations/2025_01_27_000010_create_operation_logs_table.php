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
        Schema::create('operation_logs', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('member_id')->nullable()->comment('会员ID');
            $table->bigInteger('admin_id')->nullable()->comment('管理员ID');

            // 操作信息
            $table->string('module', 50)->comment('模块: wallet=钱包, recharge=充值, withdraw=提现');
            $table->string('action', 50)->comment('操作: create=创建, update=更新, delete=删除, audit=审核');
            $table->string('method', 10)->comment('请求方式: GET, POST等');
            $table->string('url', 500)->comment('请求URL');

            // 数据信息
            $table->text('params')->nullable()->comment('请求参数JSON');
            $table->text('response')->nullable()->comment('响应结果JSON');
            $table->string('related_type', 50)->nullable()->comment('关联类型');
            $table->bigInteger('related_id')->nullable()->comment('关联ID');

            // 环境信息
            $table->string('ip', 50)->nullable()->comment('操作IP');
            $table->string('user_agent', 500)->nullable()->comment('User Agent');
            $table->integer('execute_time')->default(0)->comment('执行时间(毫秒)');

            // 状态信息
            $table->tinyInteger('status')->default(1)->comment('状态: 0=失败, 1=成功');
            $table->string('error_msg', 1000)->nullable()->comment('错误信息');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            // 索引
            $table->index('member_id');
            $table->index('admin_id');
            $table->index('module');
            $table->index('action');
            $table->index(['related_type', 'related_id']);
            $table->index('ip');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operation_logs');
    }
};

