<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {

        Schema::create('features_logs', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('bot_id')->nullable()->comment('API用户ID');
            $table->bigInteger('chat_id')->nullable()->comment('聊天ID');
            $table->foreignId('feature_id')->nullable()->constrained('features')->nullOnDelete();
            $table->string('level')->default('info')->comment('日志级别');
            $table->text('message')->nullable()->comment('日志信息');
            $table->json('meta')->nullable()->comment('元数据');
            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->unique(['chat_id', 'feature_id'], 'chat_feature_unique');
            $table->unique(['bot_id', 'feature_id'], 'feature_bot_unique');
            $table->engine = 'InnoDB';
            $table->comment('功能使用日志表');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('features_logs');
    }
};
