<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 内置商户支付功能下线：这批命令（/bd /ye /cx /xe /xq /yj /trsq）全部改由
 * merchant-pay 插件提供（三方支付业务按插件交付，避免与插件命令冲突）。
 *
 * 清理范围：features 记录 + 关联的 feature_commands / feature_upstream_endpoints /
 * features_binds / feature_data / feature_hooks。merchant_routes（群 ↔ 商户号路由）
 * 保留——它是历史业务数据，插件不依赖它但统计/排查仍有用。
 */
return new class extends Migration {
    /** 内置商户功能的 features.feature 标识 */
    private const REMOVED = [
        'custom:BindMerchant',
        'custom:GetMerchantBalance',
        'custom:QueryOrder',
        'custom:SetPayoutLimit',
        'custom:ShowPayoutLimit',
        'custom:SetWarnFunds',
        'custom:SetTradeOperator',
    ];

    public function up(): void
    {
        $featureIds = DB::table('features')->whereIn('feature', self::REMOVED)->pluck('id');

        if ($featureIds->isEmpty()) {
            return;
        }

        DB::table('feature_commands')->whereIn('feature_id', $featureIds)->delete();
        DB::table('feature_upstream_endpoints')->whereIn('feature_id', $featureIds)->delete();
        DB::table('features_binds')->whereIn('feature_id', $featureIds)->delete();
        DB::table('feature_data')->whereIn('feature_id', $featureIds)->delete();
        DB::table('feature_hooks')->whereIn('feature_id', $featureIds)->delete();
        DB::table('features')->whereIn('id', $featureIds)->delete();
    }

    public function down(): void
    {
        // 功能记录由 telegram:sync-features 在代码存在时自动重建；
        // 内置类已删除，这里不做恢复（插件安装会重建插件版）。
    }
};
