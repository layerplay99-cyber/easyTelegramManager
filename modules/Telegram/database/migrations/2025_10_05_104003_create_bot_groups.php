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
        if (Schema::hasTable('bot_groups')) {
            return;
        }

        Schema::create('bot_groups', function (Blueprint $table) {
            $table->id()->unique()->comment('id');
            $table->string('bot_id')->comment('所属Bot ID');
            $table->string('name')->nullable()->comment('所在群名称');
            $table->string('chat_id')->comment('群聊ID');
            $table->string('title')->nullable()->comment('群标题');
            $table->text('description')->nullable()->comment('群描述');
            $table->string('invite_link')->nullable()->comment('邀请链接');
            $table->string('type')->default('group')->comment('群类型');
            $table->json('settings')->nullable()->comment('群设置');
            $table->integer('group_id')->default(0)->comment('分组ID');
            $table->boolean('enabled')->default(true);
            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->engine = 'InnoDB';
            $table->comment('机器人所在群');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('bot_groups');
    }
};
