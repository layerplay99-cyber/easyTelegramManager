<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 给 users.username 加唯一索引，从数据库层面杜绝重复账号。
// 升级前若已有重复用户名（如反复 seed 产生的多个 catchadmin），先按用户名去重
// （保留 id 最小、即最早的原始记录），否则唯一索引会因冲突建不上。
//
// 注意：User 模型使用了软删除 + 全局作用域，会隐藏已软删的行，且 ->delete() 只软删、
// 行仍物理存在，唯一索引依旧冲突。因此这里一律用 DB::table 原生语句做物理删除，
// 既绕过全局作用域（能准确统计重复），也避免只软删不生效。
return new class () extends Migration {
    public function up(): void
    {
        // 1) 去重：每个重复 username 只保留 id 最小的一条（物理删除其余）
        $duplicatedUsernames = DB::table('users')
            ->select('username')
            ->groupBy('username')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('username');

        foreach ($duplicatedUsernames as $username) {
            $keepId = DB::table('users')->where('username', $username)->min('id');
            DB::table('users')->where('username', $username)->where('id', '<>', $keepId)->delete();
        }

        // 2) 加唯一索引（已存在则跳过）
        if (! Schema::hasIndex('users', 'users_username_unique')) {
            Schema::table('users', function ($table) {
                $table->unique('username', 'users_username_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('users', 'users_username_unique')) {
            Schema::table('users', function ($table) {
                $table->dropUnique('users_username_unique');
            });
        }
    }
};
