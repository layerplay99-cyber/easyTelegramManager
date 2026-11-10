<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('套餐名称');
            $table->integer('days')->comment('有效天数');
            $table->decimal('price', 20, 8)->default(0)->comment('价格（余额扣款）');
            $table->boolean('status')->default(1)->comment('1=上架 0=下架');
            $table->integer('sort')->default(0)->comment('排序');
            $table->string('remark', 500)->nullable()->comment('说明');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
