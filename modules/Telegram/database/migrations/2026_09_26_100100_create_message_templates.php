<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 消息模板（结构化 blocks）
 *
 * 不再存「带 {effect_id:x} 魔法串的纯文本」：那种做法要自己解析、
 * 且 offset 用字符数算（Telegram 要求 UTF-16 code unit），带 emoji 必然错位。
 * blocks 形如：
 *   [{"t":"text","v":"亲爱的 "},{"t":"var","k":"nickname"},
 *    {"t":"emoji","id":"5368324170671202286","alt":"🎉"},
 *    {"t":"effect","id":"5104841241405538173"}]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title')->comment('模板名称');
            $table->json('blocks')->comment('结构化内容');
            $table->string('channel', 10)->default('bot')->comment('bot|user');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->engine = 'InnoDB';
            $table->comment('消息模板表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
