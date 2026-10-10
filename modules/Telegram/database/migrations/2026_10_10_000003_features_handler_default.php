<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * features.handler 允许为空
 *
 * 新体系里功能统一由 driver 执行，不再靠 handler 类名，因此后台新建功能时
 * 根本不会提交 handler。该列建表时是 NOT NULL 且无默认值，导致后台
 * 「功能列表 → 新增」直接报：
 *   SQLSTATE[HY000]: 1364 Field 'handler' doesn't have a default value
 */
return new class extends Migration {
    public function up()
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        Schema::table('features', function (Blueprint $table) {
            $table->string('handler')->nullable()->default('')->change();
        });
    }

    public function down()
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        Schema::table('features', function (Blueprint $table) {
            $table->string('handler')->nullable(false)->default('')->change();
        });
    }
};
