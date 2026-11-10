<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 功能计费级别
 *
 * free = 免费功能，永久可用；paid = 付费功能，归属账号需处于有效套餐期内。
 * 默认 free：存量功能升级后行为完全不变，平台按需在后台改成 paid。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->string('access_level', 16)->default('free')->after('enabled')
                ->comment('free=免费 paid=需有效套餐');
        });
    }

    public function down(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn('access_level');
        });
    }
};
