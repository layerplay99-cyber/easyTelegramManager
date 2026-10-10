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
     * 是否还有代码里写了、但库里没有的功能（后台自动扫描用，避免每次列表都写库）
     */
    public function hasPending(): bool
    {
        foreach (self::discover() as $class) {
            if (! Features::query()->where('feature', 'custom:' . $class::featureKey())->exists()) {
                return true;
            }
        }

        return false;
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

        // 兼容旧数据：早期 featureKey 默认返回完整类名，标识很长且暴露目录结构。
        // 先按新（短）标识找，找不到再按旧的完整类名找，找到就把它迁移到短标识，
        // 避免产生两条重复功能记录。
        $existing = Features::query()->where('feature', $key)->first();

        if (! $existing) {
            $legacyKey = 'custom:' . $class;

            $existing = Features::query()->where('feature', $legacyKey)->first();

            if ($existing) {
                $existing->feature = $key;
                $existing->save();
            }
        }

        $payload = [
            'name' => $class::featureName(),
            'category' => $class::category(),
            'type' => 'command',
            'requestType' => 'message',
            'location' => 'local',
            // 用哪个执行器由功能代码声明：默认是自己，也可复用已有执行器
            'driver' => $class::driver(),
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
     * 同步命令：代码里的声明只作为「兜底默认值」
     *
     * 命令（/ye 之类）由后台自定义，代码里写的只是默认值：
     * 库里没有该命令时才按代码声明创建；已存在（含后台改过的）一律不动，
     * 避免每次同步把后台配置覆盖回去。
     */
    protected function syncCommand(int $featureId, array $command): void
    {
        if (empty($command['command'])) {
            return;
        }

        FeatureCommands::query()->firstOrCreate(
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
                'enabled' => true,
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