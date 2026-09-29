<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 群发任务（发到群组，不做私聊）
 *
 * blocks 是渲染快照：模板后来被改了，历史任务不受影响。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_sends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('channel', 10)->default('bot')->comment('bot|user');

            // 走哪个 bot 发（取 api_token）
            $table->unsignedBigInteger('bot_id')->nullable();

            $table->json('blocks')->comment('渲染快照');
            $table->json('chat_ids')->comment('目标群 chat_id 列表');

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('success')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 20)->default('pending')->comment('pending|running|finished');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->index('status', 'idx_send_status');
            $table->engine = 'InnoDB';
            $table->comment('群发任务表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_sends');
    }
};
