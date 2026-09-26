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

        Schema::create('group_configs', function (Blueprint $table) {
            $table->id();
            $table->integer('groupId')->comment('群ID');
            $table->string('mid')->nullable()->comment('商户ID');
            $table->string('customers')->nullable()->comment('多客服');
            $table->string('welcome')->nullable()->comment('欢迎语');
            $table->string('replyLang')->default('zh_CN')->comment('回复语言,默认中文');
            $table->json('autoReply')->nullable()->comment('自动回复配置');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->unique(['groupId'], 'idx_group_configs_groupId');

            $table->engine = 'InnoDB';
            $table->comment('群配置表');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {

    }
};
