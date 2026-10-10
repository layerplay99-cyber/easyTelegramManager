<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 给已安装的环境补「上游接口配置」菜单
 *
 * TelegramMenusSeeder 只在初始化播种；已上线的库需要这条迁移把菜单挂上去。
 * 位置：附加功能 → 上游接口配置（与「三方配置」「三方接口」同级）
 * 对应页面：web/src/views/telegram/thirdConfigEndpoint/index.vue
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        // 找到「附加功能」目录
        $parent = DB::table('permissions')
            ->where('module', 'telegram')
            ->where('permission_name', '附加功能')
            ->first();

        if (! $parent) {
            return;
        }

        $exists = DB::table('permissions')
            ->where('module', 'telegram')
            ->where('permission_mark', 'tconfendpoint')
            ->exists();

        if ($exists) {
            return;
        }

        $now = time();

        DB::table('permissions')->insert([
            'parent_id'       => $parent->id,
            'permission_name' => '上游接口配置',
            'route'           => 'tconfendpoint',
            'icon'            => 'arrows-right-left',
            'module'          => 'telegram',
            'permission_mark' => 'tconfendpoint',
            'component'       => '/telegram/thirdConfigEndpoint/index.vue',
            'redirect'        => '',
            'keepalive'       => 1,
            'type'            => 2,
            'hidden'          => 0,
            'sort'            => 4,
            'active_menu'     => '',
            'creator_id'      => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
            'deleted_at'      => 0,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('module', 'telegram')
            ->where('permission_mark', 'tconfendpoint')
            ->delete();
    }
};
