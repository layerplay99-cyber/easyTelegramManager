<?php
declare(strict_types=1);

namespace Modules\Telegram;

use Catch\Support\Module\Installer as ModuleInstaller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Telegram\Providers\TelegramServiceProvider;

class Installer extends ModuleInstaller
{
    /**
     * 数据表列表
     */
    private const TABLES = [
        'bots',
        'bot_groups',
        'features',
        'features_binds',
        'telegram_api_users',
    ];

    /**
     * 功能分类
     */
    private const FEATURE_CATEGORIES = [
        'bot' => '机器人功能',
        'realMan' => '真人账号功能',
    ];

    /**
     * 模块信息
     */
    protected function info(): array
    {
        return [
            'title' => 'Telegram',
            'name' => 'telegram',
            'path' => 'Telegram',
            'keywords' => 'Telegram, 机器人, Bot, 消息管理',
            'description' => 'Telegram 机器人管理模块，支持多机器人管理、群组管理、消息推送等功能',
            'provider' => TelegramServiceProvider::class,
            'version' => '1.0.0',
        ];
    }

    /**
     * 安装模块时执行
     */
    public function install(): void
    {
        parent::install();
    }

    /**
     * 需要安装的 Composer 包
     */
    protected function requirePackages(): void
    {
        // Telegram 模块所需的包
        // 注意：这些包应该已经在主 composer.json 中定义，这里仅作为示例
        // 如果需要自动安装，可以取消注释以下代码

        try {
            // $this->command->info('正在安装 Telegram 相关依赖包...');

            // Telegram Bot SDK - 用于机器人 API 操作
            // $this->composer()->require('irazasyed/telegram-bot-sdk:^3.15');

            // MadelineProto - 用于 Telegram 用户 API 操作
            // $this->composer()->require('danog/madelineproto:^8.6');

            // QR Code - 用于生成二维码
            // $this->composer()->require('endroid/qr-code:^6.0');

            // $this->command->info('✓ 依赖包安装完成');
        } catch (\Exception $e) {
            $this->command->warn('⚠ 依赖包安装失败: ' . $e->getMessage());
            $this->command->line('  提示: 请确保这些包已在主 composer.json 中定义');
        }
    }

    /**
     * 卸载时需要移除的 Composer 包
     */
    protected function removePackages(): void
    {
        // 由于这些包可能被其他模块使用，暂不自动移除
        // 如果确定要移除，可以取消注释以下代码

        try {
            // $this->command->warn('正在移除 Telegram 相关依赖包...');

            // $this->composer()->remove('irazasyed/telegram-bot-sdk');
            // $this->composer()->remove('danog/madelineproto');
            // $this->composer()->remove('endroid/qr-code');

            // $this->command->info('✓ 依赖包移除完成');
        } catch (\Exception $e) {
            $this->command->warn('⚠ 依赖包移除失败: ' . $e->getMessage());
        }
    }

    /**
     * 功能数据不再由安装流程播种
     *
     * 统一标准：所有功能都通过后台「功能列表」创建（由 Drivers/ 下的驱动自动发现），
     * 或执行 php artisan telegram:sync-features 同步。不再有硬编码的内置功能清单。
     */

    /**
     * 模块安装后的自定义操作
     */
    public function installed(): void
    {
        $this->command->newLine();
        $this->command->info('🎉 Telegram 模块安装成功！');
        $this->command->newLine();

        // 显示安装统计信息
        $this->showInstallationStats();

        // 显示下一步操作指南
        $this->showNextSteps();

        // 显示卸载提示
        $this->showUninstallTips();
    }

    /**
     * 显示安装统计信息
     */
    protected function showInstallationStats(): void
    {
        $stats = [];

        // 统计菜单和权限
        if (Schema::hasTable('permissions')) {
            $menuCount = DB::table('permissions')
                ->where('module', 'telegram')
                ->count();

            if ($menuCount > 0) {
                $stats[] = ['菜单和权限', "✓ {$menuCount} 条记录"];
            } else {
                $stats[] = ['菜单和权限', '✗ 未导入'];
            }
        }

        // 统计功能数据（按分类）
        if (Schema::hasTable('features')) {
            $totalCount = DB::table('features')->count();

            if ($totalCount > 0) {
                foreach (self::FEATURE_CATEGORIES as $category => $label) {
                    $categoryCount = DB::table('features')
                        ->where('category', $category)
                        ->count();

                    $enabledCount = DB::table('features')
                        ->where('category', $category)
                        ->where('enabled', 1)
                        ->count();

                    if ($categoryCount > 0) {
                        $stats[] = [$label, "✓ {$enabledCount}/{$categoryCount} 已启用"];
                    }
                }

                $stats[] = ['功能数据总计', "✓ {$totalCount} 条记录"];
            } else {
                $stats[] = ['功能数据', '✗ 未初始化'];
            }
        }

        // 统计数据表
        $existingTables = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $existingTables[] = $table;
            }
        }

        $tableCount = count($existingTables);
        $totalTables = count(self::TABLES);

        if ($tableCount === $totalTables) {
            $stats[] = ['数据表', "✓ {$tableCount}/{$totalTables} 全部创建"];
        } elseif ($tableCount > 0) {
            $stats[] = ['数据表', "⚠ {$tableCount}/{$totalTables} 部分创建"];
        } else {
            $stats[] = ['数据表', "✗ 0/{$totalTables} 未创建"];
        }

        if (!empty($stats)) {
            $this->command->table(['组件', '状态'], $stats);
            $this->command->newLine();
        }
    }

    /**
     * 显示下一步操作指南
     */
    protected function showNextSteps(): void
    {
        $this->command->info('📋 下一步操作指南：');
        $this->command->newLine();

        $steps = [
            ['步骤', '操作', '说明'],
            [
                '1',
                '分配权限',
                '在后台【权限管理 → 角色管理】中为角色分配 Telegram 模块权限'
            ],
            [
                '2',
                '配置机器人',
                '在【机器人管理】中添加和配置 Telegram 机器人（需要 Bot Token）'
            ],
            [
                '3',
                '设置 Webhook',
                '为每个机器人设置 Webhook URL 以接收 Telegram 消息'
            ],
            [
                '4',
                '绑定群组',
                '在【群组管理】中添加需要管理的 Telegram 群组'
            ],
            [
                '5',
                '配置功能',
                '在【附加功能】中为机器人和群组绑定所需功能'
            ],
        ];

        $this->command->table($steps[0], array_slice($steps, 1));
        $this->command->newLine();
    }

    /**
     * 显示卸载提示
     */
    protected function showUninstallTips(): void
    {
        $this->command->info('💡 卸载模块方法：');
        $this->command->newLine();
        $this->command->line('   <fg=yellow>方法一：使用命令卸载（推荐）</>');
        $this->command->line('   php artisan catch:module:uninstall telegram');
        $this->command->newLine();
        $this->command->line('   <fg=yellow>方法二：手动卸载</>');
        $this->command->line('   1. 回滚迁移: php artisan catch:migrate:rollback telegram');
        $this->command->line('   2. 删除模块注册: 编辑 storage/app/modules.json，移除 telegram 条目');
        $this->command->line('   3. 清理权限数据: 在数据库中删除 module=\'telegram\' 的权限记录');
        $this->command->line('   4. 删除模块目录: 删除 modules/Telegram 目录');
        $this->command->newLine();

        $this->command->warn('⚠ 警告：卸载模块将删除所有相关数据，请谨慎操作并提前备份！');
        $this->command->newLine();
    }

    /**
     * 模块卸载时执行
     */
    public function uninstall(): void
    {
        try {
            $this->command->warn('正在卸载 Telegram 模块...');
            $this->command->newLine();

            // 显示将要删除的数据统计
            $this->showUninstallStats();

            // 确认卸载
            if (!$this->command->confirm('确定要卸载 Telegram 模块吗？这将删除所有相关数据！', false)) {
                $this->command->info('已取消卸载操作');
                return;
            }

            parent::uninstall();

            $this->command->newLine();
            $this->command->info('✓ Telegram 模块已成功卸载');
        } catch (\Exception $e) {
            $this->command->error('✗ 卸载失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 显示卸载统计信息
     */
    protected function showUninstallStats(): void
    {
        $stats = [];

        // 统计权限数据
        if (Schema::hasTable('permissions')) {
            $permissionCount = DB::table('permissions')
                ->where('module', 'telegram')
                ->count();

            if ($permissionCount > 0) {
                $stats[] = ['权限数据', "{$permissionCount} 条记录将被删除"];
            }
        }

        // 统计各个表的数据
        $tableStats = [
            'bots' => '机器人',
            'bot_groups' => '群组',
            'features' => '功能',
            'features_binds' => '功能绑定',
            'telegram_api_users' => 'API用户',
        ];

        foreach ($tableStats as $table => $label) {
            if (Schema::hasTable($table)) {
                $count = DB::table($table)->count();
                if ($count > 0) {
                    $stats[] = [$label, "{$count} 条记录将被删除"];
                }
            }
        }

        // 统计数据表
        $existingTables = array_filter(self::TABLES, fn($table) => Schema::hasTable($table));
        $tableCount = count($existingTables);

        if ($tableCount > 0) {
            $stats[] = ['数据表', "{$tableCount} 个表将被删除"];
        }

        if (!empty($stats)) {
            $this->command->table(['项目', '影响'], $stats);
            $this->command->newLine();
        } else {
            $this->command->info('未找到需要删除的数据');
        }
    }
}
