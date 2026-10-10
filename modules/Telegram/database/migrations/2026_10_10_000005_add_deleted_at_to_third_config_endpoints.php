<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * third_config_endpoints 补 deleted_at
 *
 * ThirdConfigEndpoint 继承 CatchModel（带 SoftDeletes 全局作用域），查询会自动附加
 * deleted_at 条件，但建表迁移里没有这一列 → 只要解析上游接口（拉单/查单 API）就抛
 * 「Unknown column 'bmthird_config_endpoints.deleted_at' in 'where clause'」，
 * 充值/提现下单直接失败。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('third_config_endpoints', 'deleted_at')) {
            Schema::table('third_config_endpoints', function (Blueprint $table) {
                $table->unsignedInteger('deleted_at')->default(0)->comment('0=未删除');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('third_config_endpoints', 'deleted_at')) {
            Schema::table('third_config_endpoints', function (Blueprint $table) {
                $table->dropColumn('deleted_at');
            });
        }
    }
};
