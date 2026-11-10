<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 插件审核设置（单行，id=1）
 *
 * 脚本审核永远执行；AI 审核由后台开关控制，开关打开才调用模型。
 * 走 OpenAI 兼容的 /chat/completions 接口，所以 base_url + model + key 都可配，
 * 换成自建/其它厂商的兼容网关不用改代码。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_settings', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('ai_enabled')->default(0)->comment('1=AI 参与审核');
            $table->string('ai_base_url', 200)->default('https://api.openai.com/v1');
            $table->string('ai_model', 100)->default('gpt-4o-mini');
            $table->string('ai_api_key', 255)->default('');
            $table->integer('ai_timeout')->default(30);
            $table->integer('updated_by')->default(0);
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
            // CatchModel 全局带 deleted_at = 0 作用域，缺列会直接 SQL 报错
            $table->integer('deleted_at')->default(0);
        });

        DB::table('plugin_settings')->insert([
            'id' => 1,
            'ai_enabled' => 0,
            'ai_base_url' => 'https://api.openai.com/v1',
            'ai_model' => 'gpt-4o-mini',
            'ai_api_key' => '',
            'ai_timeout' => 30,
            'updated_by' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_settings');
    }
};
