<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 方案 B：平台商户（租户）维度 + 上游入站密钥对
 *
 * 命名约定：平台租户外键统一叫 tenant_id，指向 merchants.id。
 * 仓库里已有的 merchant_id 全是「上游业务商户号字符串」（payment_channels、
 * merchant_routes、interactive_sessions、group_configs.mid），一律不动、不复用，
 * 避免同一个列名出现「整数租户 id」与「字符串商户号」两种语义。
 *
 * tenant_id = 0 表示平台侧 / 共享数据。
 */
return new class extends Migration
{
    /** Telegram 模块全部业务表（含两张没有 creator_id 的回执 / 日志表） */
    private const TELEGRAM_TABLES = [
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

    public function up(): void
    {
        if (! Schema::hasTable('merchants')) {
            Schema::create('merchants', function (Blueprint $table) {
                $table->id();
                $table->string('name')->comment('商户名称');
                $table->string('code', 64)->unique()->comment('平台内部短码，与上游商户号无关');
                $table->boolean('status')->default(true)->comment('启用状态');
                $table->string('contact')->nullable()->comment('联系人');
                $table->creatorId();
                $table->createdAt();
                $table->updatedAt();
                $table->deletedAt();

                $table->engine = 'InnoDB';
                $table->comment('平台商户 / 租户');
            });
        }

        foreach (['users', 'roles', 'departments', 'jobs'] as $table) {
            $this->addTenantId($table);
        }

        if (! Schema::hasColumn('users', 'is_merchant_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_merchant_admin')->default(false)->comment('商户超管');
            });
        }

        foreach (self::TELEGRAM_TABLES as $table) {
            $this->addTenantId($table);
        }

        $this->addUpstreamKeyColumns();
    }

    public function down(): void
    {
        if (Schema::hasTable('thirdapi_config')) {
            foreach (['access_key', 'secret_key', 'key_status', 'key_version', 'key_rotated_at'] as $column) {
                if (Schema::hasColumn('thirdapi_config', $column)) {
                    Schema::table('thirdapi_config', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }

        foreach (array_merge(['users', 'roles', 'departments', 'jobs'], self::TELEGRAM_TABLES) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, fn (Blueprint $table) => $table->dropColumn('tenant_id'));
            }
        }

        if (Schema::hasColumn('users', 'is_merchant_admin')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_merchant_admin'));
        }

        Schema::dropIfExists('merchants');
    }

    private function addTenantId(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $blueprint->unsignedBigInteger('tenant_id')
                ->default(0)
                ->comment('平台商户 id，0 = 平台侧 / 共享');
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $blueprint->index('tenant_id', 'idx_' . $table . '_tenant_id');
        });
    }

    private function addUpstreamKeyColumns(): void
    {
        if (! Schema::hasTable('thirdapi_config')) {
            return;
        }

        $columns = [
            'access_key' => fn (Blueprint $table) => $table->string('access_key', 64)->nullable()->unique()->comment('下发给三方的接入标识'),
            'secret_key' => fn (Blueprint $table) => $table->text('secret_key')->nullable()->comment('APP_KEY 加密后的接入密钥，明文只下发一次'),
            'key_status' => fn (Blueprint $table) => $table->unsignedTinyInteger('key_status')->default(0)->comment('0 未生成 / 1 启用 / 2 已吊销'),
            'key_version' => fn (Blueprint $table) => $table->unsignedInteger('key_version')->default(0)->comment('轮换次数'),
            'key_rotated_at' => fn (Blueprint $table) => $table->timestamp('key_rotated_at')->nullable()->comment('最近生成时间'),
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn('thirdapi_config', $column)) {
                continue;
            }

            Schema::table('thirdapi_config', fn (Blueprint $table) => $definition($table));
        }
    }
};
