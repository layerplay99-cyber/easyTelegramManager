<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 清理早期原型遗留的 base 功能：钱包四命令已由内置 Drivers/Custom/Wallet
 * 实现（category = bot，需绑上游），不再重复存在一套 base 版本。
 */
return new class extends Migration {
    private const REMOVED = [
        'custom:walletBills',
    ];

    public function up(): void
    {
        $ids = DB::table('features')->whereIn('feature', self::REMOVED)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('feature_commands')->whereIn('feature_id', $ids)->delete();
        DB::table('features_binds')->whereIn('feature_id', $ids)->delete();
        DB::table('feature_data')->whereIn('feature_id', $ids)->delete();
        DB::table('feature_hooks')->whereIn('feature_id', $ids)->delete();
        DB::table('features')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
    }
};
