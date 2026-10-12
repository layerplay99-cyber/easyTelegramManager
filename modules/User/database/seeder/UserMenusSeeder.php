<?php

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 把「用户管理」（管理员列表）挂到「权限管理」目录下。
 *
 * 之前这步写在迁移 2026_10_07_000001_seed_user_management_menu.php 里，
 * 但它用 importTreeData() 时把「用户管理」当顶层元素、写死 parent_id=1。
 * importTreeData 只对嵌套 children 重映射 parent_id，对顶层元素不处理，
 * 而「权限管理」经 PermissionsMenusSeeder 后用的是自增 id（不是 1），
 * 导致「用户管理」成了孤儿节点 → 侧边栏不显示，无法创建管理员。
 *
 * 改在这里（seeder，在 PermissionsMenusSeeder 之后执行，父级已存在），
 * 并动态查找父级 id、对已有行显式修正 parent_id / hidden，保证幂等可重跑。
 */
return new class extends Seeder
{
    public function run(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('permissions')) {
            return;
        }

        $parentId = DB::table('permissions')
            ->where('module', 'permissions')
            ->where('permission_name', '权限管理')
            ->value('id');

        // 父级还没建好：交给 PermissionsMenusSeeder 先建，下次 seed 再挂
        if (! $parentId) {
            return;
        }

        $now = time();

        // 用户管理（管理员）页面：插入或修正
        $menu = DB::table('permissions')
            ->where('module', 'user')
            ->where('permission_name', '用户管理')
            ->where('permission_mark', 'Users')
            ->first();

        if (! $menu) {
            $userId = DB::table('permissions')->insertGetId([
                'parent_id'       => $parentId,
                'permission_name' => '用户管理',
                'route'           => 'users',
                'icon'            => 'users',
                'module'          => 'user',
                'permission_mark' => 'Users',
                'component'       => '/user/index.vue',
                'redirect'        => null,
                'keepalive'       => 1,
                'type'            => 2,
                'hidden'          => 0,
                'sort'            => 1,
                'active_menu'     => '',
                'creator_id'      => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
            ]);
        } else {
            $userId = $menu->id;
            // 关键：孤儿节点（parent_id 指向不存在的记录）在此修正回来，并设为可见
            DB::table('permissions')->where('id', $userId)->update([
                'parent_id' => $parentId,
                'hidden'    => 0,
            ]);
        }

        // 标准按钮权限（幂等）
        $actions = [
            '列表'     => ['Users@index', 1],
            '读取'     => ['Users@show', 3],
            '新增'     => ['Users@store', 2],
            '更新'     => ['Users@update', 4],
            '删除'     => ['Users@destroy', 5],
            '禁用/启用' => ['Users@enable', 6],
        ];

        foreach ($actions as $name => [$mark, $sort]) {
            $exists = DB::table('permissions')
                ->where('module', 'user')
                ->where('permission_name', $name)
                ->where('permission_mark', $mark)
                ->first();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'parent_id'       => $userId,
                    'permission_name' => $name,
                    'route'           => '',
                    'icon'            => '',
                    'module'          => 'user',
                    'permission_mark' => $mark,
                    'component'       => '',
                    'redirect'        => '',
                    'keepalive'       => 1,
                    'type'            => 3,
                    'hidden'          => 1,
                    'sort'            => $sort,
                    'active_menu'     => '',
                    'creator_id'      => 1,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                    'deleted_at'      => 0,
                ]);
            } else {
                // 修正可能错挂的父级
                DB::table('permissions')->where('id', $exists->id)->update(['parent_id' => $userId]);
            }
        }
    }
};
