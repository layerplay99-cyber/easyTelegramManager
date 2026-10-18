<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * third_config_endpoints 的时间戳列改成 unsignedInteger
 *
 * 建表迁移用的是 $table->timestamps()，生成的是 timestamp 列；
 * 但本项目所有表（含同类的 third_api_endpoints）用的都是 unsignedInteger，
 * 因为 CatchAdmin 的 CatchModel 写入的是 unix 整数时间戳。
 *
 * 结果就是「上游接口配置」一保存就报错：
 *   SQLSTATE[22007] ... Incorrect datetime value: '1792308769' for column 'updated_at'
 * 保存必然失败、表里永远 0 行 —— 表现为「定制化配好了，上游下拉切走再切回来就没了」，
 * 实际是根本没写进去（前端会弹保存失败）。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('third_config_endpoints')) {
            return;
        }

        Schema::table('third_config_endpoints', function (Blueprint $table) {
            if (Schema::hasColumn('third_config_endpoints', 'created_at')) {
                $table->unsignedInteger('created_at')->nullable()->default(0)->change();
            }

            if (Schema::hasColumn('third_config_endpoints', 'updated_at')) {
                $table->unsignedInteger('updated_at')->nullable()->default(0)->change();
            }
        });
    }

    /**
     * 不回滚列类型：表里存的已经是 unix 整数，改回 timestamp 会再炸一次
     */
    public function down(): void
    {
        //
    }
};
