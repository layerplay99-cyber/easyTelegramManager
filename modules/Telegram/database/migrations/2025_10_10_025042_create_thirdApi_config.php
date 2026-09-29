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
        // ⚠️ 表名必须全小写：模型 ThirdapiConfig 查的是 thirdapi_config，
        // Windows/MySQL 不区分大小写看不出问题，Linux 下会建成 thirdApi_config 导致 1146 表不存在。
        if (Schema::hasTable('thirdapi_config')) {
            return;
        }

        Schema::create('thirdapi_config', function (Blueprint $table) {
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
        Schema::dropIfExists('thirdapi_config');
    }
};
