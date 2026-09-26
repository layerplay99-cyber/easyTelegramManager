<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('features')) {
            return;
        }

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('功能名称');
            $table->enum('category', ['system', 'custom', 'bot', 'realMan'])->default('custom')->comment('功能分类');
            $table->enum('type', ['command', 'ocr', 'notify', 'interaction'])->default('command')->comment('功能类型');
            $table->enum('requestType', ['message', 'callback_query', 'inline_query'])->default('message')->comment('请求类型');
            $table->enum('location', ['local', 'external'])->default('local')->comment('数据来源');
            $table->string('feature')->comment('功能标识');
            $table->text('description')->comment('功能描述');
            $table->string('handler')->comment('处理类');
            $table->json('config')->nullable()->comment('配置项');
            $table->boolean('enabled')->default(true);
            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->engine = 'InnoDB';
            $table->comment('功能表');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('features');
    }
};
