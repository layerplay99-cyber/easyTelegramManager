<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Facades\File;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;

/**
 * 自定义功能注册器
 *
 * 扫描 Drivers/Custom/ 目录，把所有继承 BaseCustomFeature 的类同步进数据库：
 *   - features表：按 featureKey 幂等 upsert（不会覆盖后台已改的 enabled 开关）
 *   - feature_commands 表：按 command 幂等 upsert
 *
 * 开发者新增功能只需：新建一个类 + 执行 php artisan telegram:sync-features
 */
class CustomFeatureRegistry
{
    /**
     * 扫描到的自定义功能类
     *
     * @return array<int, class-string<BaseCustomFeature>>
     */
    public static function discover(): array
    {
        $path = __DIR__ . '/Drivers/Custom';

        if (! is_dir($path)) {
            return [];
        }

        $classes = [];

        foreach (File::allFiles($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = 'Modules\\Telegram\\Services\\Feature\\Drivers\\Custom\\' . $relative;

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(BaseCustomFeature::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * 同步到数据库
     *
     * @return array<int, string> 同步的功能名称列表
     */
    public function sync(): array
    {
        $synced = [];

        foreach (self::discover() as $class) {
            $feature = $this->syncFeature($class);

            $synced[] = $class::featureName();

            foreach ($class::commands() as $command) {
                $this->syncCommand($feature->id, $command);
            }
        }

        return $synced;
    }

    /**
     * 同步单个功能（幂等）
     */
    protected function syncFeature(string $class): Features
    {
        $key = 'custom:' . $class::featureKey();

        $existing = Features::query()->where('feature', $key)->first();

        $payload = [
            'name' => $class::featureName(),
            'category' => $class::category(),
            'type' => 'command',
            'requestType' => 'message',
            'location' => 'local',
            'driver' => $class::key(),
            'trigger' => $class::triggerName(),
            'feature' => $key,
            'description' => $class::featureDescription(),
            // handler 留空：执行走 driver，不依赖类名字符串
            'handler' => '',
        ];

        // config 只在首次写入，避免覆盖后台已改的配置
        if (! $existing) {
            $payload['config'] = $class::defaultConfig();
            $payload['enabled'] = true;
        }

        if ($existing) {
            $existing->fill($payload)->save();

            return $existing;
        }

        return Features::query()->create($payload);
    }

    /**
     * 同步命令（幂等，不覆盖后台已改的启用状态）
     */
    protected function syncCommand(int $featureId, array $command): void
    {
        if (empty($command['command'])) {
            return;
        }

        FeatureCommands::query()->updateOrCreate(
            ['command' => $command['command']],
            [
                'feature_id' => $featureId,
                'command' => $command['command'],
                'usage' => $command['usage'] ?? null,
                'description' => $command['description'] ?? null,
                'scope' => $command['scope'] ?? 'group',
                'permission' => $command['permission'] ?? 'all',
                'params' => $command['params'] ?? [],
                'reply_template' => $command['reply_template'] ?? null,
            ]
        );
    }

    /**
     * 后台展示用：所有自定义功能的定义
     */
    public function definitions(): array
    {
        $list = [];

        foreach (self::discover() as $class) {
            $list[] = [
                'key' => $class::key(),
                'feature_key' => $class::featureKey(),
                'name' => $class::featureName(),
                'description' => $class::featureDescription(),
                'category' => $class::category(),
                'trigger' => $class::triggerName(),
                'group' => $class::group(),
                'config_schema' => $class::configSchema(),
                'commands' => $class::commands(),
                'default_config' => $class::defaultConfig(),
            ];
        }

        return $list;
    }
}