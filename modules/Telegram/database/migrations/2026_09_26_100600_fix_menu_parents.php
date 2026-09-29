<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修复菜单树父子关系
 *
 * 背景：菜单 seeder（TelegramMenusSeeder 等）是从老库导出的，把 id / parent_id 写死了
 * （机器人管理写死 id=108，子项写死 parent_id=108）。全新库插入时 id 由自增重新分配，
 * 顶层菜单拿到的 id 不再是 108，于是子项全部变成孤儿 —— 表现就是
 * 「权限管理 / 号码采集 / 机器人管理 / 附加功能」点开都是空白（顶层菜单没有子路由）。
 *
 * 这里改成按 route + permission_mark 认父，与自增 id 无关，可重复执行。
 */
return new class extends Migration
{
    /**
     * 顶层菜单 route => 该分组下的页面 permission_mark
     */
    private const MENU_TREE = [
        'telegram' => [
            '/collect'  => ['phone', 'sCanLog', 'telegramApiUser'],
            '/activity' => ['features', 'thridConfig'],
            '/telegram' => ['botGroupGroup', 'botGroups', 'bots', 'messageTemplate', 'emojis'],
        ],
        'permissions' => [
            '/permission' => ['departments', 'jobs', 'permissions', 'roles'],
        ],
    ];

    public function up(): void
    {
        foreach (self::MENU_TREE as $module => $groups) {
            foreach ($groups as $topRoute => $pages) {
                $topId = $this->findTopId($module, $topRoute);

                if (! $topId) {
                    continue;
                }

                foreach ($pages as $mark) {
                    $pageId = $this->findPageId($module, $mark);

                    if (! $pageId) {
                        continue;
                    }

                    DB::table('permissions')
                        ->where('id', $pageId)
                        ->update(['parent_id' => $topId]);
                }
            }
        }

        $this->reportOrphans();
    }

    public function down(): void
    {
        // 数据修复，不做回滚
    }

    private function findTopId(string $module, string $route): ?int
    {
        $id = DB::table('permissions')
            ->where('module', $module)
            ->where('type', 1)
            ->where('route', $route)
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function findPageId(string $module, string $mark): ?int
    {
        $id = DB::table('permissions')
            ->where('module', $module)
            ->where('type', 2)
            ->where('permission_mark', $mark)
            ->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * 打印仍然悬空的菜单，方便确认修复是否彻底
     */
    private function reportOrphans(): void
    {
        $ids = DB::table('permissions')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $orphans = DB::table('permissions')
            ->where('parent_id', '<>', 0)
            ->whereNotIn('parent_id', $ids)
            ->get(['id', 'permission_name', 'parent_id']);

        if ($orphans->isEmpty()) {
            return;
        }

        $message = $orphans->map(fn ($o) => "{$o->id}({$o->permission_name}) parent={$o->parent_id}")
            ->implode(', ');

        logger()->warning('菜单仍有悬空项: ' . $message);
    }
};
