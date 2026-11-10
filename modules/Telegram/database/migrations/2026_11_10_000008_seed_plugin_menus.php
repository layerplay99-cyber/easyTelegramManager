<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 给已安装的环境补「插件管理」菜单
 *
 * 目录：插件管理（/plugin），下挂一个页面：插件列表。
 * 按 permission_mark 查重，幂等可重跑；跑完重新登录/刷新即出现在侧边栏。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = time();

        $directory = DB::table('permissions')
            ->where('module', 'telegram')
            ->where('permission_mark', '')
            ->where('permission_name', '插件管理')
            ->first();

        if (! $directory) {
            $id = DB::table('permissions')->insertGetId([
                'parent_id'       => 0,
                'permission_name' => '插件管理',
                'route'           => '/plugin',
                'icon'            => 'grid',
                'module'          => 'telegram',
                'permission_mark' => '',
                'component'       => '/layout/index.vue',
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 1,
                'hidden'          => 0,
                'sort'            => 6,
                'active_menu'     => '',
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
            ]);

            $directory = DB::table('permissions')->find($id);
        }

        $exists = DB::table('permissions')
            ->where('module', 'telegram')
            ->where('permission_mark', 'pluginList')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('permissions')->insert([
            'parent_id'       => $directory->id,
            'permission_name' => '插件列表',
            'route'           => 'pluginList',
            'icon'            => 'puzzle',
            'module'          => 'telegram',
            'permission_mark' => 'pluginList',
            'component'       => '/telegram/plugins/index.vue',
            'redirect'        => '',
            'keepalive'       => 1,
            'type'            => 2,
            'hidden'          => 0,
            'sort'            => 1,
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

        $directory = DB::table('permissions')
            ->where('module', 'telegram')
            ->where('permission_mark', '')
            ->where('permission_name', '插件管理')
            ->first();

        if (! $directory) {
            return;
        }

        DB::table('permissions')->where('parent_id', $directory->id)->delete();
        DB::table('permissions')->where('id', $directory->id)->delete();
    }
};
