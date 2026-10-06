<?php

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

return new class extends Seeder
{
    /**
     * Run the seeder.
     *
     * @return void
     */
    public function run(): void
    {
        $menus = $this->menus();

        importTreeData($menus, 'permissions');
    }

    /**
     * Develop（开发工具）模块的后台菜单。
     *
     * 说明：
     * - 原先 menus() 返回空数组，导致后台完全没有「开发工具」菜单
     *   （Schema / 代码生成 / 模块管理 都无法进入）。此处按路由与前端页面补齐。
     * - route      = 前端路由路径
     * - component  = 前端页面文件（相对 web/src/views）
     * - module     = 所属模块，必须是 develop（菜单按 module 归属）
     * - importTreeData() 是幂等的：按 permission_name + module + permission_mark
     *   先查后插，重复执行不会产生重复菜单。
     */
    public function menus(): array
    {
        $now = time();

        return [
            [
                'parent_id'       => 0,
                'permission_name' => '开发工具',
                'route'           => '/develop',
                'icon'            => 'tool',
                'module'          => 'develop',
                'permission_mark' => '',
                'component'       => '/layout/index.vue',
                'redirect'        => '/develop/schema',
                'keepalive'       => 1,
                'type'            => 1,
                'hidden'          => 0,
                'sort'            => 90,
                'active_menu'     => '',
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
                'children'        => [
                    [
                        'permission_name' => 'Schema',
                        'route'           => '/develop/schema',
                        'icon'            => 'database',
                        'module'          => 'develop',
                        'permission_mark' => '',
                        'component'       => '/develop/schema/index.vue',
                        'redirect'        => '',
                        'keepalive'       => 1,
                        'type'            => 1,
                        'hidden'          => 0,
                        'sort'            => 1,
                        'active_menu'     => '',
                        'creator_id'      => 1,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                        'deleted_at'      => 0,
                    ],
                    [
                        'permission_name' => '代码生成',
                        'route'           => '/develop/generate',
                        'icon'            => 'code',
                        'module'          => 'develop',
                        'permission_mark' => '',
                        'component'       => '/develop/generate/index.vue',
                        'redirect'        => '',
                        'keepalive'       => 1,
                        'type'            => 1,
                        'hidden'          => 0,
                        'sort'            => 2,
                        'active_menu'     => '',
                        'creator_id'      => 1,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                        'deleted_at'      => 0,
                    ],
                    [
                        'permission_name' => '模块管理',
                        'route'           => '/develop/module',
                        'icon'            => 'appstore',
                        'module'          => 'develop',
                        'permission_mark' => '',
                        'component'       => '/develop/module/index.vue',
                        'redirect'        => '',
                        'keepalive'       => 1,
                        'type'            => 1,
                        'hidden'          => 0,
                        'sort'            => 3,
                        'active_menu'     => '',
                        'creator_id'      => 1,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                        'deleted_at'      => 0,
                    ],
                ],
            ],
        ];
    }
};
