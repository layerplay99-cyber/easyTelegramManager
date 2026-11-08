<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 清理没有代码声明、也没有功能引用的孤儿平台接口
 *
 * 来源：
 *  - merchant.trade / merchant.info：GetMerchantBalance（查询商户余额）早期作为
 *    「一个功能声明多个接口」的示例多写了两个，handle() 从不调用，但
 *    feature_upstream_endpoints 会把它俩关联到该功能，导致后台「上游接口配置」
 *    里出现三行、都标着「查询商户余额」。
 *  - verify.tmp.endpoint：联调留下的临时记录（名称「临时」），从未有代码声明。
 *
 * PlatformEndpointRegistry::sync() 只按代码声明 upsert，不会删除库里已不再声明
 * 的接口，所以光删代码声明清不掉这些记录，这里显式清一次存量。
 *
 * 三张表一起清，避免留下指向已删除 code 的孤儿配置：
 *  - third_api_endpoints        平台接口规范
 *  - feature_upstream_endpoints 功能 → 接口关联
 *  - third_config_endpoints     上游对接口的路径 / 方法定制
 */
return new class () extends Migration {
    private const CODES = ['merchant.trade', 'merchant.info', 'verify.tmp.endpoint'];

    public function up(): void
    {
        foreach (['third_api_endpoints', 'feature_upstream_endpoints', 'third_config_endpoints'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $column = $table === 'third_api_endpoints' ? 'code' : 'endpoint_code';

            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)->whereIn($column, self::CODES)->delete();
        }
    }

    public function down(): void
    {
        // 不回滚：这些记录没有任何代码声明与功能引用，恢复只会重新制造孤儿数据。
    }
};
