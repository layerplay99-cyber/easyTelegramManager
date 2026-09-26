<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 合并 tusers 到 telegram_api_users
 *
 * 背景：tusers（2025-09-30）与 telegram_api_users（2025-11-02）基础字段
 * （app_id / app_hash / phone / login_status / status）高度重合，属于重复建表。
 * telegram_api_users 才是「telegram客服」权威表（bot_groups.app_id、
 * features_binds.bot_id、service_peoples.app_id 都指向它的 app_id），
 * 而 tusers 仅被「采集/扫描」子系统使用。
 *
 * 本迁移把 tusers 特有字段并入 telegram_api_users，并删除 tusers 表。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_api_users')) {
            Schema::table('telegram_api_users', function (Blueprint $table) {
                if (! Schema::hasColumn('telegram_api_users', 'scan_count')) {
                    $table->integer('scan_count')->default(0)->comment('今日扫描次数');
                }
                if (! Schema::hasColumn('telegram_api_users', 'scan_date')) {
                    $table->dateTime('scan_date')->nullable()->comment('记录当前扫描计数的日期');
                }
                if (! Schema::hasColumn('telegram_api_users', 'code')) {
                    $table->string('code')->nullable()->comment('验证码');
                }
            });
        }

        // 删除重复的 tusers 表
        Schema::dropIfExists('tusers');
    }

    public function down(): void
    {
        if (Schema::hasTable('telegram_api_users')) {
            Schema::table('telegram_api_users', function (Blueprint $table) {
                $table->dropColumn(['scan_count', 'scan_date', 'code']);
            });
        }
        // 历史 tusers 迁移已删除，down 仅回滚本次新增列，不重建 tusers。
    }
};
