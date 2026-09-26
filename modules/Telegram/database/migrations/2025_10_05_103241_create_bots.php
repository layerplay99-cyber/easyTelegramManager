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
        if (Schema::hasTable('bots')) {
            return;
        }

        Schema::create('bots', function (Blueprint $table) {
            $table->id()->unique()->comment('id');
            $table->string('api_token')->comment('api_token');
            $table->string('username')->comment('Bot用户名');
            $table->string('url_token')->comment('url_token');
            $table->string('webhook_url')->comment('Webhook URL');
            $table->text('description')->comment('Bot描述');
            $table->unsignedBigInteger('owner_id')->nullable()->comment('拥有者ID');
            $table->boolean('enabled')->default(true);
            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->engine = 'InnoDB';
            $table->comment('机器人列表');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('bots');
    }
};
