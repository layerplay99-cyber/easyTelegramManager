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
        if (Schema::hasTable('scanlogs')) {
            return;
        }

        Schema::create('scanlogs', function (Blueprint $table) {
            $table->id()->unique();
            $table->bigInteger('tuser_id')->comment('使用的飞机号ID');
            $table->string('phone')->nullable()->comment('被扫电话');
            $table->integer('days')->nullable()->comment('N天内活跃');
            $table->string('first_name')->nullable()->comment('名');
            $table->string('last_name')->nullable()->comment('姓氏');
            $table->string('username')->nullable()->comment('用户名');
            $table->text('bio')->nullable()->comment('简介');
            $table->string('avatar_path')->nullable()->comment('用户头像');
            $table->integer('is_active')->default(0)->comment('是否在线');
            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->engine = 'InnoDB';
            $table->index(['tuser_id', 'days', 'is_active', 'id'], 'idx_tuser_days_active_id');
            $table->comment('scanlogs');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('scanlogs');
    }
};
