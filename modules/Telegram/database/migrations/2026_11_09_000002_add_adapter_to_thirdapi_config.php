<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 三方配置增加「上游适配器」标识
 *
 * 一个上游实例选一个协议插件（key 来自 UpstreamAdapterRegistry::options()）。
 * 留空 = 直通，行为与加字段之前完全一致，所以升级零破坏。
 *
 * key 存的是「协议族」（一家厂商一个插件类），不是上游实例 ID：
 * 同一厂商的不同商户号 / 环境共用同一个插件，base_url、token、密钥、
 * path 这些部署差异继续留在后台配置里，不写进代码。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('thirdapi_config')) {
            return;
        }

        if (Schema::hasColumn('thirdapi_config', 'adapter')) {
            return;
        }

        Schema::table('thirdapi_config', function (Blueprint $table) {
            $table->string('adapter', 64)->nullable()->after('name')
                ->comment('上游协议适配器 key，空=直通，见 config/upstream_adapters.php');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('thirdapi_config') || ! Schema::hasColumn('thirdapi_config', 'adapter')) {
            return;
        }

        Schema::table('thirdapi_config', function (Blueprint $table) {
            $table->dropColumn('adapter');
        });
    }
};
