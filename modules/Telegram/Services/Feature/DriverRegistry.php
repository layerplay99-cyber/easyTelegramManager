<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Facades\File;
use Modules\Telegram\Contracts\FeatureDriver;
use Modules\Telegram\Services\Feature\Drivers\FeatureStoreDriver;
use Modules\Telegram\Services\Feature\Drivers\HookReceiveDriver;
use Modules\Telegram\Services\Feature\Drivers\HttpRequestDriver;
use Modules\Telegram\Services\Feature\Drivers\MiniAppDriver;
use Modules\Telegram\Services\Feature\Drivers\TelegramApiDriver;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdApiEndpoints;
use Modules\Telegram\Services\Feature\PlatformEndpointRegistry;

/**
 * 驱动注册表
 *
 * 后台「功能列表」不写 handler 类名、不写 JSON：
 * 这里扫描 Drivers/ 目录拿到所有 FeatureDriver 实现，
 * 把它们的 key / label / group / configSchema 暴露给前端自动渲染表单。
 *
 * 新增一个驱动 = 在 Drivers/ 目录加一个类，后台自动出现该选项，无需改前端。
 */
class DriverRegistry
{
    /**
     * @var array<string, class-string<FeatureDriver>>|null
     */
    protected static ?array $drivers = null;

    /**
     * 扫描并返回所有驱动
     *
     * @return array<string, class-string<FeatureDriver>>
     */
    public static function all(): array
    {
        if (self::$drivers !== null) {
            return self::$drivers;
        }

        $drivers = [];

        foreach (self::discover() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (! $reflection->implementsInterface(FeatureDriver::class)) {
                continue;
            }

            $drivers[$class::key()] = $class;
        }

        return self::$drivers = $drivers;
    }

    /**
     * 解析驱动实例
     */
    public static function resolve(string $key): ?FeatureDriver
    {
        $class = self::all()[$key] ?? null;

        return $class ? app($class) : null;
    }

    /**
     * 后台表单所需：全部驱动的声明
     */
    public static function definitions(): array
    {
        $list = [];

        foreach (self::all() as $key => $class) {
            $list[] = [
                'key' => $key,
                'label' => $class::label(),
                'group' => $class::group(),
                'triggers' => $class::triggers(),
                'config_schema' => self::resolveSources($class::configSchema()),
            ];
        }

        return $list;
    }

    /**
     * 把 source 解析成实际选项，前端直接渲染成下拉
     */
    protected static function resolveSources(array $schema): array
    {
        foreach ($schema as $i => $field) {
            if (empty($field['source'])) {
                continue;
            }

            $schema[$i]['options'] = self::options($field['source']);
        }

        return array_values($schema);
    }

    /**
     * 各 source 的选项来源
     */
    protected static function options(string $source): array
    {
        return match ($source) {
            'scope_types' => [
                ['value' => 'group', 'label' => '群（按群ID 存）'],
                ['value' => 'bot', 'label' => '机器人（按机器人ID 存）'],
                ['value' => 'user', 'label' => '用户（按用户ID 存）'],
                ['value' => 'global', 'label' => '全局（所有群共用一份）'],
            ],
            'token_positions' => [
                ['value' => 'header', 'label' => '请求头'],
                ['value' => 'query', 'label' => 'URL 参数'],
                ['value' => 'body', 'label' => '请求体'],
            ],
            'telegram_methods' => \Modules\Telegram\Services\Feature\Drivers\TelegramApiDriver::METHODS,
            'parse_modes' => [
                ['value' => '', 'label' => '纯文本'],
                ['value' => 'HTML', 'label' => 'HTML'],
                ['value' => 'Markdown', 'label' => 'Markdown'],
            ],
            'miniapp_modes' => [
                ['value' => 'keyboard', 'label' => '聊天底部键盘'],
                ['value' => 'inline', 'label' => '内联按钮'],
                ['value' => 'menu', 'label' => '菜单按钮'],
            ],
            'third_api_configs' => ThirdApiConfig::query()
                ->get(['id', 'name'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->toArray(),
            'third_api_endpoints' => PlatformEndpointRegistry::options(),
                 'platform_endpoints' => PlatformEndpointRegistry::options(),
            default => [],
        };
    }

    /**
     * 扫描 Drivers 目录
     *
     * @return array<int, string>
     */
    protected static function discover(): array
    {
        $path = __DIR__ . '/Drivers';
        $classes = [];

        foreach (File::allFiles($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $classes[] = 'Modules\\Telegram\\Services\\Feature\\Drivers\\' . $relative;
        }

        return $classes;
    }

    /**
     * 清空缓存（开发期新增驱动后调用）
     */
    public static function flush(): void
    {
        self::$drivers = null;
    }
}