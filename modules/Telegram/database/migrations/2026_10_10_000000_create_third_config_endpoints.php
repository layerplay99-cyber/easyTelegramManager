<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上游 × 接口 的路径映射表
 *
 * 背景：third_api_endpoints 是「平台接口规范」，同一份 path_template 对所有上游通用。
 * 但现实中同一个业务语义（如「获取余额」）在不同上游的路径并不相同：
 *   上游1 https://www.example.com  → /api/webhook/getBalance
 *   上游2 https://www.example2.com → /api/webhook/getAmount
 *
 * 因此需要一层「上游对平台接口的专属实现」：
 *   - path          该上游专用路径；留空表示跟随平台默认 path_template
 *   - 若 path 以 http:// 或 https:// 开头，视为绝对地址，忽略上游 base_url
 *   - method/headers/query 逐项覆盖，留空回退平台默认值
 *   - enabled=0 表示该上游不支持此接口
 *
 * 功能代码只引用平台 code，不硬编码任何 URL，全部由后台配置。
 */
return new class () extends Migration {
    public function up(): void
    {
        // 表名必须全小写（Linux 下大小写敏感）
        if (Schema::hasTable('third_config_endpoints')) {
            return;
        }

        Schema::create('third_config_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('third_config_id')->comment('上游配置 thirdapi_config.id');
            // 用 code 而非外键 id：平台接口由 PlatformEndpointRegistry 按 code 幂等同步
            $table->string('endpoint_code', 64)->comment('平台接口标识 third_api_endpoints.code');
            $table->string('path')->nullable()->comment('该上游专用路径；留空用平台默认；http(s)开头视为绝对地址');
            $table->string('method', 16)->nullable()->comment('覆盖请求方法；留空用平台默认');
            $table->json('headers')->nullable()->comment('覆盖请求头；留空用平台默认');
            $table->json('query')->nullable()->comment('覆盖固定查询参数；留空用平台默认');
            $table->boolean('enabled')->default(true)->comment('该上游是否支持此接口');
            $table->string('remark')->nullable()->comment('备注');
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->timestamps();

            $table->unique(['third_config_id', 'endpoint_code'], 'uniq_cfg_endpoint');
            $table->index('third_config_id', 'idx_cfg_endpoint_config');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_config_endpoints');
    }
};
