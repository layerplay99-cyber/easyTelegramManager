<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Permissions\Support\Tenancy;

/**
 * 用户归属变更时的数据过户
 *
 * 背景：后台数据按 tenant_id 隔离（DataScope）。平台用户（users.tenant_id = 0）
 * 原先在平台侧建的机器人、群、功能绑定等都是 tenant_id = 0；一旦后台把他
 * 「配置到某个商户」（tenant_id 0 → X），查询立刻收窄成 tenant_id = X，
 * 他登录后就看不到自己之前配置的任何东西了。
 *
 * 规则（只做单向、只搬自己的）：
 *   - 仅当 0 → 商户（首次归属商户）时触发；解除归属（X → 0）不搬，
 *     数据属于商户，不跟着人回平台；
 *   - 只搬 creator_id = 该用户 且 tenant_id = 0 的行；超管代配的数据留在平台侧；
 *   - 共享字典表（features / third_api_endpoints / emojis / exchange_rates）、
 *     权限体系、资金流水、操作日志不搬：它们要么是平台规范，要么属于商户账本。
 */
final class TenantDataTransfer
{
    /** 用户名下可随人过户的运营数据表 */
    private const USER_OWNED_TABLES = [
        // 机器人 / 客服号 / 采集
        'bots', 'telegram_api_users', 'phones', 'scanlogs', 'service_peoples',
        // 群与群配置
        'bot_groups', 'group_configs', 'group_admins', 'group_members', 'group_groups',
        // 功能绑定与会话
        'features_binds', 'feature_data', 'interactive_sessions',
        // 消息模板与发送记录
        'message_templates', 'message_sends',
        // 上游配置与商户路由（谁绑的商户号跟谁走）
        'thirdapi_config', 'third_api_endpoints', 'third_config_endpoints',
        'merchant_routes',
    ];

    /**
     * 把用户创建的平台数据过户到指定商户，返回受影响的记录数
     */
    public static function claimPlatformData(int $userId, int $tenantId): int
    {
        if ($userId <= 0 || $tenantId <= 0) {
            return 0;
        }

        $moved = 0;

        foreach (self::USER_OWNED_TABLES as $table) {
            try {
                if (! Schema::hasTable($table)
                    || ! Schema::hasColumn($table, Tenancy::COLUMN)
                    || ! Schema::hasColumn($table, 'creator_id')) {
                    continue;
                }

                $moved += DB::table($table)
                    ->where(Tenancy::COLUMN, 0)
                    ->where('creator_id', $userId)
                    // 软删除的行一并过户，否则恢复后又会「消失」
                    ->update([Tenancy::COLUMN => $tenantId]);
            } catch (\Throwable) {
                // 单表失败不阻断整体过户（表结构差异 / 脏数据）
            }
        }

        return $moved;
    }
}
