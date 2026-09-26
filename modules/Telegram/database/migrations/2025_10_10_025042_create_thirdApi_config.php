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
        if (Schema::hasTable('thirdApi_config')) {
            return;
        }

        Schema::create('thirdApi_config', function (Blueprint $table) {
            $table->id()->unique();
            $table->string('name')->nullable()->comment('名称');
            $table->string('api_url')->nullable()->comment('api');
            $table->string('token')->nullable()->comment('token');
            $table->string('public_key')->nullable()->comment('公钥');
            $table->string('secrept_key')->nullable()->comment('密钥');
            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->engine = 'InnoDB';
            $table->comment('三方API配置');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('thirdApi_config');
    }
};
