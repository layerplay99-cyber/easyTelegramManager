<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\User\Models\User;

// 给 users.username 加唯一索引，从数据库层面杜绝重复账号。
// 升级前若已有重复用户名（如反复 seed 产生的多个 catchadmin），
// 先按用户名去重（保留 id 最小的原始记录），否则唯一索引会因冲突建不上。
return new class () extends Migration {
    public function up(): void
    {
        // 1) 去重：每个重复用户名只保留 id 最小的一条
        $duplicatedUsernames = User::select('username')
            ->groupBy('username')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('username');

        foreach ($duplicatedUsernames as $username) {
            $keepId = User::where('username', $username)->min('id');
            User::where('username', $username)->where('id', '<>', $keepId)->delete();
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
