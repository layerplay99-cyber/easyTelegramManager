<?php

declare(strict_types=1);

namespace Modules\Permissions\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * 租户（平台商户）元数据
 *
 * 只负责「这张表有没有 tenant_id」「它的平台数据能不能给商户看」，
 * 不参与可见性计算本身（那是 DataScope / HierarchyScope 的事）。
 *
 * 运营数据默认私有：tenant_id = 0 的历史 / 平台数据只对平台侧可见。
 * 只有 SHARED_TABLES 里的平台规范 / 字典表才对商户开放共享，
 * 避免未上线阶段把超管建的一堆机器人、群、订单全放给商户。
 */
class Tenancy
{
    public const COLUMN = 'tenant_id';

    public const TABLES = [
        'users', 'roles', 'departments', 'jobs',
        'members', 'wallets', 'exchange_rates', 'payment_channels',
        'recharge_orders', 'withdraw_orders', 'ledgers',
        'risk_control_rules', 'risk_control_logs', 'operation_logs',
        'notifications', 'transaction_limits',
        'phones', 'scanlogs', 'bots', 'telegram_api_users',
        'service_peoples', 'emojis',
        'bot_groups', 'features', 'features_binds', 'features_logs',
        'thirdapi_config', 'group_configs', 'group_admins',
        'group_members', 'group_groups',
        'feature_commands', 'feature_data', 'third_api_endpoints',
        'feature_hooks', 'feature_logs', 'merchant_routes',
        'interactive_sessions', 'third_config_endpoints', 'feature_hook_routes',
        'message_templates', 'message_sends', 'message_send_logs',
    ];

    /** 这些是平台规范 / 字典，允许商户只读共享 */
    public const SHARED_TABLES = [
        'features', 'third_api_endpoints', 'exchange_rates', 'emojis',
    ];

    /** 按表名缓存列检查结果，避免每次 creating 都查 information_schema */
    protected static array $supportCache = [];

    public static function supports(mixed $model): bool
    {
        if (! $model instanceof Model) {
            return false;
        }

        $table = $model->getTable();

        if (! in_array($table, self::TABLES, true)) {
            return false;
        }

        if (! array_key_exists($table, static::$supportCache)) {
            try {
                static::$supportCache[$table] = Schema::hasColumn($table, self::COLUMN);
            } catch (\Throwable) {
                // 全新安装时 provider 会先于迁移执行，表还不存在
                static::$supportCache[$table] = false;
            }
        }

        return static::$supportCache[$table];
    }

    public static function sharedVisible(mixed $model): bool
    {
        if (! $model instanceof Model) {
            return false;
        }

        return in_array($model->getTable(), self::SHARED_TABLES, true);
    }

    public static function flush(): void
    {
        static::$supportCache = [];
    }
}
