<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 菜单 hidden 语义归一（幂等）：
 *   框架语义 1=显示(Enable) / 2=隐藏(Disable)，isHidden() 只认 2。
 *   历史存量是 0=显示 / 1=隐藏，导致后台「隐藏」无效、且每次 app:install
 *   的 ensureMenusVisible() 会把管理员手动隐藏的菜单强刷回 0。
 *
 * 只修非法旧值 0 与下钻页，绝不动 2（保留管理员的隐藏设置）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable("permissions")) {
            return;
        }

        // ① 目录(1)/页面(2) 的历史非法值 0 → 1（显示）
        DB::table("permissions")
            ->whereIn("type", [1, 2])
            ->where("hidden", 0)
            ->update(["hidden" => 1]);

        // ② 下钻页（route 含 :param）不该出现在侧栏：seed 出来是 1（显示），
        //    归一为 2（隐藏）。已经是 2 的不动。全新库由 InstallApp 在 seed 后兜底。
        DB::table("permissions")
            ->whereIn("type", [1, 2])
            ->where("route", "like", "%:%")
            ->where("hidden", 1)
            ->update(["hidden" => 2]);
    }

    public function down(): void
    {
        // 数据归一不可逆：还原成 0/1 会重新引入语义错位
    }
};
