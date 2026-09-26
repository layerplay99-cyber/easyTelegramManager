<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->index()->comment('群ID');
            $table->bigInteger('user_id')->index()->comment('成员用户ID');
            $table->string('username')->nullable()->comment('用户名');
            $table->string('status', 50)->default('member')->comment('成员状态，member/left/kicked');
            $table->boolean('is_bot')->default(false)->comment('是否机器人');
            $table->timestamp('joined_at')->nullable()->comment('入群时间');
            $table->timestamp('left_at')->nullable()->comment('退群时间');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();
            $table->unique(['chat_id','user_id'], 'chat_member_unique');

            $table->engine = 'InnoDB';
            $table->comment('群成员表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_chat_members');
    }
};
