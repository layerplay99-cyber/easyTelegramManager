<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 给已安装的环境补「套餐管理」菜单
 *
 * 目录：套餐管理（/plan），下挂两个页面：套餐列表、套餐订单。
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
            ->where('permission_name', '套餐管理')
            ->first();

        if (! $directory) {
            $id = DB::table('permissions')->insertGetId([
                'parent_id'       => 0,
                'permission_name' => '套餐管理',
                'route'           => '/plan',
                'icon'            => 'gift',
                'module'          => 'telegram',
                'permission_mark' => '',
                'component'       => '/layout/index.vue',
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 1,
                'hidden'          => 0,
                'sort'            => 5,
                'active_menu'     => '',
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
            ]);

            $directory = DB::table('permissions')->find($id);
        }

        $pages = [
            ['套餐列表', 'planList', '/telegram/plans/index.vue', 'gift-top', 1],
            ['套餐订单', 'planOrder', '/telegram/planOrders/index.vue', 'receipt', 2],
        ];

        foreach ($pages as [$name, $mark, $component, $icon, $sort]) {
            $exists = DB::table('permissions')
                ->where('module', 'telegram')
                ->where('permission_mark', $mark)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('permissions')->insert([
                'parent_id'       => $directory->id,
                'permission_name' => $name,
                'route'           => $mark,
                'icon'            => $icon,
                'module'          => 'telegram',
                'permission_mark' => $mark,
                'component'       => $component,
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 2,
                'hidden'          => 0,
                'sort'            => $sort,
                'active_menu'     => '',
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $directory = DB::table('permissions')
            ->where('module', 'telegram')
            ->where('permission_mark', '')
            ->where('permission_name', '套餐管理')
            ->first();

        if (! $directory) {
            return;
        }

        DB::table('permissions')->where('parent_id', $directory->id)->delete();
        DB::table('permissions')->where('id', $directory->id)->delete();
    }
};
