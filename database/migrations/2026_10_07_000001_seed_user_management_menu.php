<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// 把「用户管理」挂到「权限管理」(id=1) 下，并补充标准按钮权限。
// 前端页面 web/src/views/user/index.vue 早已存在，只要菜单出现即可在侧边栏点进去。
// 复用 CatchAdmin 自带的 importTreeData()（按 permission_name+module+permission_mark 查重，幂等可重跑）。
return new class () extends Migration {
    public function up()
    {
        $table = 'permissions';

        // 菜单表不存在（尚未 catch:install）时跳过，避免报错
        if (! Schema::hasTable($table)) {
            return;
        }

        $now = time();

        $actions = [];
        foreach ([
            '列表'     => ['Users@index', 1],
            '读取'     => ['Users@show', 3],
            '新增'     => ['Users@store', 2],
            '更新'     => ['Users@update', 4],
            '删除'     => ['Users@destroy', 5],
            '禁用/启用' => ['Users@enable', 6],
        ] as $name => [$mark, $sort]) {
            $actions[] = [
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
            ];
        }

        $menus = [
            [
                'parent_id'       => 1, // 挂在已存在的「权限管理」(id=1) 下
                'permission_name' => '用户管理',
                'route'           => 'users',
                'icon'            => 'users',
                'module'          => 'user',
                'permission_mark' => 'Users',
                'component'       => '/user/index.vue',
                'redirect'        => null,
                'keepalive'       => 1,
                'type'            => 2,
                'hidden'          => 1,
                'sort'            => 1,
                'active_menu'     => '',
                'creator_id'      => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
                'children'        => $actions,
            ],
        ];

        importTreeData($menus, $table);
    }

    public function down()
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        // 仅清理本次新增的菜单（module=user 且 permission_mark 以 Users 开头）
        \Illuminate\Support\Facades\DB::table('permissions')
            ->where('module', 'user')
            ->where('permission_mark', 'like', 'Users%')
            ->delete();
    }
};
