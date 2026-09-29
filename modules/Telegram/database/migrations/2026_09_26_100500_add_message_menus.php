<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 补两个后台菜单：消息模板、表情素材
 *
 * 两个页面（web/src/views/telegram/messageTemplate、telegramEmojis）之前都没有菜单入口。
 * 挂到「机器人管理」下（与 bots 同父级）。幂等：permission_mark 已存在就跳过。
 *
 * ⚠️ 顶层菜单的 route 必须以 / 开头：前端把顶层菜单直接 router.addRoute()，
 * 相对路径（如 'emojis'）会让 vue-router 抛 "Invalid path"，
 * 表现就是登录成功后立刻被踢回登录页。没有父级可挂时宁可不建，也不要建成顶层。
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = time();

        $parentId = $this->resolveParentId();

        // 先修历史脏数据：已经是顶层且 route 不是绝对路径的菜单，重新挂到父级下
        if ($parentId > 0) {
            DB::table('permissions')
                ->where('parent_id', 0)
                ->whereIn('permission_mark', ['messageTemplate', 'emojis'])
                ->update(['parent_id' => $parentId]);
        }

        if ($parentId === 0) {
            // 菜单要等模块 seeder 跑完才有（首次全新安装时本迁移通常先执行），
            // 这时没有可挂的父级，直接跳过，避免造出非法的顶层路由。
            return;
        }

        $menus = [
            [
                'permission_name' => '消息模板',
                'route' => 'messageTemplate',
                'icon' => 'chat-bubble-left-right',
                'component' => '/telegram/messageTemplate/index.vue',
                'permission_mark' => 'messageTemplate',
                'sort' => 6,
            ],
            [
                'permission_name' => '表情素材',
                'route' => 'emojis',
                'icon' => 'face-smile',
                'component' => '/telegram/telegramEmojis/index.vue',
                'permission_mark' => 'emojis',
                'sort' => 7,
            ],
        ];

        $actions = [
            ['index', '列表', 1],
            ['store', '新增', 2],
            ['show', '读取', 3],
            ['update', '更新', 4],
            ['destroy', '删除', 5],
        ];

        foreach ($menus as $menu) {
            $exists = DB::table('permissions')
                ->where('module', 'telegram')
                ->where('permission_mark', $menu['permission_mark'])
                ->exists();

            if ($exists) {
                continue;
            }

            $menuId = DB::table('permissions')->insertGetId($menu + [
                'parent_id' => $parentId,
                'module' => 'telegram',
                'redirect' => '',
                'keepalive' => 1,
                'type' => 2,
                'hidden' => 1,
                'active_menu' => '',
                'creator_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => 0,
            ]);

            foreach ($actions as [$action, $name, $sort]) {
                DB::table('permissions')->insert([
                    'parent_id' => $menuId,
                    'permission_name' => $name,
                    'route' => '',
                    'icon' => '',
                    'module' => 'telegram',
                    'permission_mark' => $menu['permission_mark'] . '@' . $action,
                    'component' => '',
                    'redirect' => '',
                    'keepalive' => 1,
                    'type' => 3,
                    'hidden' => 1,
                    'sort' => $sort,
                    'active_menu' => '',
                    'creator_id' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => 0,
                ]);
            }
        }
    }

    public function down(): void
    {
        $marks = ['messageTemplate', 'emojis'];

        $ids = DB::table('permissions')
            ->where('module', 'telegram')
            ->whereIn('permission_mark', $marks)
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            return;
        }

        DB::table('permissions')->whereIn('parent_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    /**
     * 找「机器人管理」这个顶层菜单：
     * 1) 优先用 bots 菜单的父级；
     * 2) 找不到就取 telegram 模块自己的顶层菜单（type=1）。
     */
    private function resolveParentId(): int
    {
        $parentId = (int) (DB::table('permissions')->where('permission_mark', 'bots')->value('parent_id') ?: 0);

        if ($parentId > 0) {
            return $parentId;
        }

        return (int) (DB::table('permissions')
            ->where('module', 'telegram')
            ->where('type', 1)
            ->where('parent_id', 0)
            ->value('id') ?: 0);
    }
};
