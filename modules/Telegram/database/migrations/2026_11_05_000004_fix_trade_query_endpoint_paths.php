<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 修正「查询订单」在后台配过的上游路径
 *
 * 订单号改由 QueryOrder 在请求前追加，path 只写到 trades。
 * 存量 third_config_endpoints.path 里若还带着 /{id} 或 {userId}，
 * 新版参数里没有这两个 key，TemplateRenderer 会把占位渲染成 '-'，
 * 请求变成 /api/webhook/users/-/trades/-/202605487749548 —— 升级即坏。
 *
 * 这里只做两件保守的替换：
 *   {userId} → {merchant_id}（与其它商户接口的占位命名统一）
 *   末尾 /{id} 或 /{order_id} → 去掉（订单号由代码追加）
 * 其它上游自定义路径（域名不同、前缀不同等）原样保留。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('third_config_endpoints')
            || ! Schema::hasColumn('third_config_endpoints', 'path')) {
            return;
        }

        $rows = DB::table('third_config_endpoints')
            ->where('endpoint_code', 'merchant.trade.query')
            ->whereNotNull('path')
            ->get(['id', 'path']);

        foreach ($rows as $row) {
            $path = trim((string) $row->path);

            if ($path === '') {
                continue;
            }

            $fixed = str_replace(
                ['{userId}', '{{userId}}', '{{ userId }}'],
                ['{merchant_id}', '{{merchant_id}}', '{{ merchant_id }}'],
                $path
            );

            // {{id}} / {{ order_id }} / {id} / {order_id}，末尾可带斜杠
            $fixed = (string) preg_replace('#/\{\{\s*(id|order_id)\s*\}\}/?$#i', '', $fixed);
            $fixed = (string) preg_replace('#/\{(id|order_id)\}/?$#i', '', $fixed);
            $fixed = rtrim($fixed, '/');

            if ($fixed !== $path) {
                DB::table('third_config_endpoints')
                    ->where('id', $row->id)
                    ->update(['path' => $fixed]);
            }
        }
    }

    public function down(): void
    {
        // 不回滚：旧占位与新版 QueryOrder 不兼容，恢复会让请求再次出错。
    }
};
