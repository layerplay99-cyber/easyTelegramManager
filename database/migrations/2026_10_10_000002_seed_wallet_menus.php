<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 给已安装的环境补「钱包管理」菜单
 *
 * TelegramMenusSeeder 只在初始化播种，已安装的库靠这条迁移挂菜单。
 * 新增目录：钱包管理（/wallet），下挂 9 个页面：
 *   会员、钱包、充值订单、提现订单、账本流水、支付通道、风控中心、交易限额、汇率
 * 按 permission_mark 查重，幂等可重跑。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = time();

        $directory = DB::table('permissions')
            ->where('module', 'telegram')
            ->where('permission_mark', '')
            ->where('permission_name', '钱包管理')
            ->first();

        if (! $directory) {
            $id = DB::table('permissions')->insertGetId([
                'parent_id'       => 0,
                'permission_name' => '钱包管理',
                'route'           => '/wallet',
                'icon'            => 'wallet',
                'module'          => 'telegram',
                'permission_mark' => '',
                'component'       => '/layout/index.vue',
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 1,
                'hidden'          => 0,
                'sort'            => 4,
                'active_menu'     => '',
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
            ]);

            $directory = DB::table('permissions')->find($id);
        }

        $pages = [
            ['会员', 'walletMember', '/telegram/wallet/member/index.vue', 'users', 1],
            ['钱包', 'walletAccount', '/telegram/wallet/wallet/index.vue', 'wallet', 2],
            ['充值订单', 'walletRechargeOrder', '/telegram/wallet/rechargeOrder/index.vue', 'banknotes', 3],
            ['提现订单', 'walletWithdrawOrder', '/telegram/wallet/withdrawOrder/index.vue', 'arrow-up-tray', 4],
            ['账本流水', 'walletLedger', '/telegram/wallet/ledger/index.vue', 'document-text', 5],
            ['支付通道', 'walletChannel', '/telegram/wallet/paymentChannel/index.vue', 'credit-card', 6],
            ['风控中心', 'walletRisk', '/telegram/wallet/riskControl/index.vue', 'shield-check', 7],
            ['交易限额', 'walletLimit', '/telegram/wallet/transactionLimit/index.vue', 'adjustments-horizontal', 8],
            ['汇率', 'walletRate', '/telegram/wallet/exchangeRate/index.vue', 'arrows-right-left', 9],
        ];

        foreach ($pages as [$name, $mark, $component, $icon, $sort]) {
            $exists = DB::table('permissions')
                ->where('module', 'telegram')
                ->where('permission_mark', $mark)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('permissions')->insert([
                'parent_id'       => $directory->id,
                'permission_name' => $name,
                'route'           => $mark,
                'icon'            => $icon,
                'module'          => 'telegram',
                'permission_mark' => $mark,
                'component'       => $component,
                'redirect'        => '',
                'keepalive'       => 1,
                'type'            => 2,
                'hidden'          => 0,
                'sort'            => $sort,
                'active_menu'     => '',
                'creator_id'      => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
                'deleted_at'      => 0,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $directory = DB::table('permissions')
            ->where('module', 'telegram')
            ->where('permission_mark', '')
            ->where('permission_name', '钱包管理')
            ->first();

        if (! $directory) {
            return;
        }

        DB::table('permissions')->where('parent_id', $directory->id)->delete();
        DB::table('permissions')->where('id', $directory->id)->delete();
    }
};
