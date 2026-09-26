<?php

namespace Modules\Telegram\Console\Commands;

use Illuminate\Console\Command;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Services\Feature\Command\SlashCommandRegistry;

/**
 * 扫描并把代码里实现的斜杠命令同步到 features 表
 *
 * 原实现有两个致命问题：
 *   1. 类名拼成 'App\\BotModules\\xxx'，实际命名空间是
 *      Modules\\Telegram\\Services\\Feature\\Command → class_exists 永远为 false，一个命令都注册不上；
 *   2. 写入了 features 表里根本不存在的 key / default_config 列 → 直接 SQL 报错。
 *
 * 现在只做一件事：把实现了 SlashCommand 的类同步成后台可选的功能记录，
 * 已存在的按 feature（命令名）更新描述与 handler，不动 enabled 开关。
 */
class ScanActivity extends Command
{
    protected $signature = 'telegram:scan-activity';

    protected $description = '扫描并注册代码里实现的斜杠命令到 features 表';

    public function handle(SlashCommandRegistry $registry): int
    {
        $registry->discover();

        $definitions = $registry->definitions();

        if (empty($definitions)) {
            $this->warn('未发现任何斜杠命令（需实现 Modules\\Telegram\\Contracts\\SlashCommand）');

            return self::SUCCESS;
        }

        foreach ($definitions as $definition) {
            $feature = strtoupper($definition['name']);
            $shortHandler = class_basename($definition['handler']);

            $exists = Features::query()
                ->where('feature', $feature)
                ->where('category', 'bot')
                ->first();

            if ($exists) {
                // 已存在：只补齐描述与 handler，保留后台的开关与配置
                $exists->update([
                    'description' => $definition['description'],
                    'handler' => $shortHandler,
                    'type' => 'command',
                ]);

                $this->line("已更新: {$feature} → {$shortHandler}");

                continue;
            }

            Features::create([
                'name' => $definition['description'] ?: $feature,
                'category' => 'bot',
                'type' => 'command',
                'feature' => $feature,
                'description' => $definition['description'],
                'handler' => $shortHandler,
                'enabled' => 1,
                'creator_id' => 1,
            ]);

            $this->info("已注册: {$feature} → {$shortHandler}  ({$definition['usage']})");
        }

        $this->info('完成，共 ' . count($definitions) . ' 条命令。');

        return self::SUCCESS;
    }
}
