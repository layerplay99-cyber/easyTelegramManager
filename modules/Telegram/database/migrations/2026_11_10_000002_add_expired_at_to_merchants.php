<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('merchants', function (Blueprint $table) {
            // 商户有效期（空 = 永久有效）：过期后该商户下全部用户的后台接口
            // （CheckMerchantExpired）与机器人指令链路（UpdateRouter）都会拦截。
            // 用户级 users.expired_at 可对单个账号单独封顶，两者任一过期即过期。
            // 全平台时间戳统一 Unix 整型（CatchModel $dateFormat = 'U'），这里保持一致。
            $table->integer('expired_at')->nullable()->after('status');

            // 最近一次到期提醒的时间（7/3/1 天提醒各发一次，避免每天重复推送）
            $table->integer('expire_noticed_at')->nullable()->after('expired_at');
        });
    }

    public function down()
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['expired_at', 'expire_noticed_at']);
        });
    }
};
