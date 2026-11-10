<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上传安装的功能插件
 *
 * 插件包 = zip（manifest.json + PHP 功能类），安装到 modules/Telegram/Plugins/{Studly}/。
 * 本表只存「安装了什么、审核结果、包含哪些功能」，代码本体在磁盘上——
 * 插件停用时目录移到 Plugins/_disabled/，注册表只扫启用目录，所以发现逻辑不依赖本表。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_plugins', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique()->comment('插件唯一标识（manifest.slug）');
            $table->string('name', 120)->default('');
            $table->string('version', 32)->default('');
            $table->string('author', 120)->default('');
            $table->string('description', 500)->default('');
            $table->string('namespace', 120)->default('')->comment('插件命名空间（Modules\\Telegram\\Plugins\\Xxx）');
            $table->string('status', 16)->default('installed')->index()->comment('installed/disabled/rejected');
            $table->json('manifest')->nullable();
            $table->json('audit_report')->nullable()->comment('脚本审核结果');
            $table->json('ai_review')->nullable()->comment('AI 审核结果（未开启则为空）');
            $table->json('feature_ids')->nullable()->comment('该插件同步出的 features.id');
            $table->json('disabled_feature_ids')->nullable()->comment('停用前的功能开关快照');
            $table->integer('file_count')->default(0);
            $table->integer('size')->default(0)->comment('解压后字节数');
            $table->integer('installed_by')->default(0);
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
            // CatchModel 全局带 deleted_at = 0 作用域，缺列会直接 SQL 报错
            $table->integer('deleted_at')->default(0);

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_plugins');
    }
};
