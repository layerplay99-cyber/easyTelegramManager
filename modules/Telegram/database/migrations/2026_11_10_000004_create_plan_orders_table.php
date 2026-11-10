<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 50)->unique()->comment('订单号');
            $table->bigInteger('merchant_id')->default(0)->comment('商户ID（tenant_id=0 的个人账号为 0）');
            $table->bigInteger('user_id')->comment('购买账号ID');
            $table->bigInteger('plan_id')->comment('套餐ID');
            $table->string('plan_name', 100)->comment('套餐名称快照');
            $table->integer('plan_days')->comment('套餐天数快照');
            $table->decimal('price', 20, 8)->default(0)->comment('实付价格');

            // 状态：0=待支付（预留在线支付）1=已支付 2=已取消
            $table->tinyInteger('status')->default(0);
            $table->integer('paid_at')->nullable()->comment('支付时间');
            $table->integer('expired_from')->nullable()->comment('续期锚点（原到期时间或当前时间）');
            $table->integer('expired_to')->nullable()->comment('新到期时间');
            $table->string('remark', 500)->nullable()->comment('备注');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->index('merchant_id');
            $table->index('user_id');
            $table->index('plan_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_orders');
    }
};
