<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * 修复：新增机器人默认不激活（待激活）。
     *
     * 原迁移将 bots.enabled 默认值设为 true，导致新增机器人一进库就是「已激活」，
     * 但列表的 setWebhook 只在用户手动拨动开关时触发，新增时并未调用 →
     * 出现「库里标记已激活、Telegram 端却没设 webhook」的坏状态。
     * 改为默认 false，由用户在列表手动开启开关来触发 setWebhook。
     */
    public function up(): void
    {
        if (Schema::hasColumn('bots', 'enabled')) {
            DB::statement('ALTER TABLE bots ALTER enabled SET DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bots', 'enabled')) {
            DB::statement('ALTER TABLE bots ALTER enabled SET DEFAULT 1');
        }
    }
};
