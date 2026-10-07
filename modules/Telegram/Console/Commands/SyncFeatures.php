<?php

declare(strict_types=1);

namespace Modules\Telegram\Console\Commands;

use Illuminate\Console\Command;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Services\Feature\CustomFeatureRegistry;

/**
 * 注册 / 同步内置的无代码功能与命令
 *
 * 幂等：可反复执行，只更新定义、不动后台已改的 enabled 开关与绑定关系。
 */
class SyncFeatures extends Command
{
    protected $signature = 'telegram:sync-features';

    protected $description = '注册内置无代码功能（绑定商户等）到功能列表';

    /**
     * 内置功能定义
     * 每项 = 一个功能 + 若干斜杠命令
     */
    private function presets(): array
    {
        return [
            [
                'feature' => [
                    'name' => '绑定商户号',
                    'category' => 'bot',
                    'driver' => 'feature.store',
                    'trigger' => 'command',
                    'description' => '把商户号与当前群绑定，后续功能可直接引用',
                    'config' => [
                        'scope_type' => 'group',
                        'fields' => ['merchant_id' => ''],
                        'reply_template' => '✅ 已绑定商户：{{@merchant_id}}',
                        'allow_overwrite' => true,
                    ],
                ],
                'commands' => [
                    [
                        'command' => 'bm',
                        'usage' => '/bm <merchant_id>',
                        'description' => '绑定商户号到当前群',
                        'params' => [
                            ['name' => 'merchant_id', 'required' => true, 'description' => '商户号'],
                        ],
                        'reply_template' => '✅ 已绑定商户：{{@merchant_id}}',
                    ],
                ],
            ],
            [
                'feature' => [
                    'name' => '绑定客服账号',
                    'category' => 'bot',
                    'driver' => 'feature.store',
                    'trigger' => 'command',
                    'description' => '把客服账号与当前群绑定',
                    'config' => [
                        'scope_type' => 'group',
                        'fields' => ['customer_phone' => ''],
                        'reply_template' => '✅ 已绑定客服：{{@customer_phone}}',
                        'allow_overwrite' => true,
                    ],
                ],
                'commands' => [
                    [
                        'command' => 'bdcs',
                        'usage' => '/bdcs <phone>',
                        'description' => '绑定客服账号到当前群',
                        'params' => [
                            ['name' => 'customer_phone', 'required' => true, 'description' => '客服手机号'],
                        ],
                        'reply_template' => '✅ 已绑定客服：{{@customer_phone}}',
                    ],
                ],
            ],
        ];
    }

    public function handle(): int
    {
        $count = 0;

        foreach ($this->presets() as $preset) {
            $definition = $preset['feature'];

            $feature = Features::query()->updateOrCreate(
                ['feature' => 'preset:' . $definition['name']],
                [
                    'name' => $definition['name'],
                    'category' => $definition['category'],
                    'type' => 'command',
                    'requestType' => 'message',
                    'location' => 'local',
                    'driver' => $definition['driver'],
                    'trigger' => $definition['trigger'],
                    'feature' => 'preset:' . $definition['name'],
                    'description' => $definition['description'],
                    'handler' => '',
                    'config' => $definition['config'],
                    'enabled' => true,
                ]
            );

            $count++;

            foreach ($preset['commands'] as $command) {
                FeatureCommands::query()->updateOrCreate(
                    ['command' => $command['command']],
                    [
                        'feature_id' => $feature->id,
                        'command' => $command['command'],
                        'usage' => $command['usage'],
                        'description' => $command['description'],
                        'scope' => 'group',
                        'permission' => 'all',
                        'params' => $command['params'],
                        'reply_template' => $command['reply_template'],
                        'enabled' => true,
                    ]
                );

                $this->line("  命令 /{$command['command']} → {$definition['name']}");
            }

            $this->info("已注册内置功能：{$definition['name']}（driver={$definition['driver']}）");
        }

        $this->newLine();

        // 自动发现开发者写在 Drivers/Custom/ 下的自定义功能
        $custom = app(CustomFeatureRegistry::class)->sync();

        foreach ($custom as $name) {
            $this->info("已注册自定义功能：{$name}");
            $count++;
        }

        $this->newLine();
        $this->info("完成，共 {$count} 个功能。可在「功能列表」里调整配置与绑定。");

        return self::SUCCESS;
    }
}