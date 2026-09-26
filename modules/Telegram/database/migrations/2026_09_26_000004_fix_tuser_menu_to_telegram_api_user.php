<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 修复菜单死链：原「账号仓库」菜单（permission_mark=tUser）指向已删除的
 * /telegram/tUser/index.vue。tusers 已并入 telegram_api_users，扫描/客服账号
 * 统一在 telegram_api_users 管理，故把该菜单重指向 telegramApiUser 页。
 *
 * 线上 permissions 表是早期 seed 写入的，不会随种子重跑自动更新，
 * 因此用迁移在部署时一并修正，无需人工执行 SQL。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        // 1) 先去重：删除重复且 hidden 的「Telegram客服」菜单（route=apiuser）。
        //    它原本就指向 telegramApiUser 页，且 permission_mark 与「账号仓库」完全相同。
        //    必须先删，否则下一步把「账号仓库」重指为 telegramApiUser 时，
        //    会与它现有的 permission_mark 撞唯一键，导致迁移失败。保留可见的「账号仓库」即可。
        $dupeId = DB::table('permissions')
            ->where('route', 'apiuser')
            ->where('component', '/telegram/telegramApiUser/index.vue')
            ->value('id');
        if ($dupeId) {
            DB::table('permissions')->where('parent_id', $dupeId)->delete();
            DB::table('permissions')->where('id', $dupeId)->delete();
        }

        // 2) 重指「账号仓库」菜单本体（route / permission_mark / component）
        DB::table('permissions')
            ->where('permission_mark', 'tUser')
            ->update([
                'route' => 'telegramApiUser',
                'permission_mark' => 'telegramApiUser',
                'component' => '/telegram/telegramApiUser/index.vue',
            ]);

        // 3) 兜底：任何仍指向已删 tUser 页面的组件路径
        DB::table('permissions')
            ->where('component', 'like', '%/tUser/%')
            ->update(['component' => '/telegram/telegramApiUser/index.vue']);

        // 4) 子权限 mark tUser@* -> telegramApiUser@*，匹配新控制器动作
        DB::table('permissions')
            ->where('permission_mark', 'like', 'tUser@%')
            ->update(['permission_mark' => DB::raw("REPLACE(permission_mark, 'tUser@', 'telegramApiUser@')")]);
    }

    public function down(): void
    {
        // tUser 页已删除，回滚仅恢复 permission_mark 便于追溯，不恢复已失效的组件路径
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('permission_mark', 'telegramApiUser')
            ->where('component', '/telegram/telegramApiUser/index.vue')
            ->update(['permission_mark' => 'tUser']);

        DB::table('permissions')
            ->where('permission_mark', 'like', 'telegramApiUser@%')
            ->update(['permission_mark' => DB::raw("REPLACE(permission_mark, 'telegramApiUser@', 'tUser@')")]);
    }
};
