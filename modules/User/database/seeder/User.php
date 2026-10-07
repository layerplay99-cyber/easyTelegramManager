<?php

use Illuminate\Database\Seeder;
use Modules\User\Models\User;

return new class extends Seeder
{
    /**
     * Run the seeder.
     *
     * @return void
     */
    public function run(): void
    {
        // 幂等：已存在 catchadmin 则跳过，避免 app:module:install 反复跑 seed 时重复插入
        if (User::where('username', 'catchadmin')->exists()) {
            return;
        }

        $user = new User([
            'username' => 'catchadmin',

            'email' => 'catch@admin.com',

            'password' => 'catchadmin',

            'creator_id' => 1,

            'department_id' => 0
        ]);

        $user->save();
    }
};
