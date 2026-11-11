<?php

namespace App\Console\Commands;

use App\Support\TenantDataTransfer;
use Illuminate\Console\Command;
use Modules\User\Models\User;

/**
 * 存量修复：把「已归属商户、但数据还留在平台」的账号数据过户到其商户
 *
 * 场景：后台给用户配置商户（users.tenant_id 0 → X）时，新版本会自动把他名下的
 * 平台数据一起过户；但在这个修复上线前配置过的账号，机器人 / 群仍然是
 * tenant_id = 0，登录后按商户隔离查不到。跑一次本命令即可补齐。
 *
 * 幂等：只动 tenant_id = 0 且 creator_id = 该用户 的行，可重复执行。
 */
class ClaimTenantData extends Command
{
    protected $signature = 'tenant:claim-data {user? : 只处理指定用户 ID，缺省处理全部商户用户}';

    protected $description = '把商户用户名下仍归属平台（tenant_id=0）的运营数据过户到其商户';

    public function handle(): int
    {
        $query = User::query()->where('tenant_id', '>', 0);

        if ($userId = $this->argument('user')) {
            $query->where('id', (int) $userId);
        }

        $users = $query->get(['id', 'username', 'tenant_id']);

        if ($users->isEmpty()) {
            $this->warn('没有归属商户的用户，无需处理。');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($users as $user) {
            $moved = TenantDataTransfer::claimPlatformData((int) $user->id, (int) $user->tenant_id);

            $total += $moved;

            $this->line(sprintf(
                '用户 #%d %s → 商户 #%d：过户 %d 条',
                $user->id,
                $user->username,
                (int) $user->tenant_id,
                $moved
            ));
        }

        $this->info("完成，共过户 {$total} 条记录。");

        return self::SUCCESS;
    }
}
