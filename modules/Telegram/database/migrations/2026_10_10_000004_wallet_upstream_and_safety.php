<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 钱包接入上游体系 + 资金安全加固
 *
 * 1) 上游实例（thirdapi_config）补「通道ID」：接入支付上游必须配置
 *    拉单 API、查单 API（走 third_config_endpoints 按接口覆盖）、
 *    key（public_key）、密钥（secrept_key）、通道ID（channel_id）。
 * 2) 充值/提现订单记录由哪个上游承接（third_config_id），
 *    并把 channel_id 改成可空——新链路用上游，不再强依赖支付通道。
 * 3) 加幂等键：外部重复提交同一 idempotency_key 只会产生一笔订单。
 * 4) withdraw_orders.order_no 补唯一索引（原本只有普通索引，幂等弱）。
 */
return new class extends Migration {
    public function up()
    {
        if (Schema::hasTable('thirdapi_config') && ! Schema::hasColumn('thirdapi_config', 'channel_id')) {
            Schema::table('thirdapi_config', function (Blueprint $table) {
                $table->string('channel_id', 100)->nullable()->comment('上游通道ID/支付类型')->after('secrept_key');
            });
        }

        foreach (['recharge_orders', 'withdraw_orders'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                if (! Schema::hasColumn($t->getTable(), 'third_config_id')) {
                    $t->unsignedBigInteger('third_config_id')->nullable()->comment('承接该单的上游实例')->after('channel_id');
                }

                if (! Schema::hasColumn($t->getTable(), 'idempotency_key')) {
                    $t->string('idempotency_key', 100)->nullable()->after('third_config_id');
                }

                // 新链路用上游承接，支付通道不再是必填
                if (Schema::hasColumn($t->getTable(), 'channel_id')) {
                    $t->unsignedBigInteger('channel_id')->nullable()->change();
                }
            });

            if (! Schema::hasColumn($table, 'idempotency_key')) {
                continue;
            }

            try {
                Schema::table($table, function (Blueprint $t) {
                    $t->unique('idempotency_key', $t->getTable() . '_idempotency_key_unique');
                });
            } catch (\Throwable $e) {
                // 已存在索引则忽略
            }
        }

        // 提现单号补唯一索引（有重复数据时跳过，避免迁移中断）
        if (Schema::hasTable('withdraw_orders') && Schema::hasColumn('withdraw_orders', 'order_no')) {
            $duplicated = DB::table('withdraw_orders')
                ->select('order_no')
                ->groupBy('order_no')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if (! $duplicated) {
                try {
                    Schema::table('withdraw_orders', function (Blueprint $t) {
                        $t->unique('order_no', 'withdraw_orders_order_no_unique');
                    });
                } catch (\Throwable $e) {
                    // 已存在则忽略
                }
            }
        }
    }

    public function down()
    {
        foreach (['recharge_orders', 'withdraw_orders'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                if (Schema::hasColumn($t->getTable(), 'third_config_id')) {
                    $t->dropColumn('third_config_id');
                }

                if (Schema::hasColumn($t->getTable(), 'idempotency_key')) {
                    $t->dropColumn('idempotency_key');
                }
            });
        }

        if (Schema::hasTable('thirdapi_config') && Schema::hasColumn('thirdapi_config', 'channel_id')) {
            Schema::table('thirdapi_config', function (Blueprint $table) {
                $table->dropColumn('channel_id');
            });
        }
    }
};
