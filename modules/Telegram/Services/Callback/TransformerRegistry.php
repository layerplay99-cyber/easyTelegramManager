<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Callback;

use Modules\Telegram\Contracts\CallbackTransformer;
use Modules\Telegram\Services\Callback\Transformers\DefaultTransformer;

/**
 * 回调消息转换器注册表
 *
 * 动态扫描 config/transformers.php 里配的目录，不做跨请求缓存——
 * 开发者改完/删掉一个类，后台重新打开功能就能看到最新的，不用清缓存、不用跑命令。
 * 类数量是个位数，扫一次的成本可以忽略。
 */
class TransformerRegistry
{
    /**
     * @var array<string, class-string<CallbackTransformer>>|null
     */
    protected static ?array $classes = null;

    /**
     * @var array<int, class-string<CallbackTransformer>>
     */
    protected static array $manual = [];

    /**
     * 显式注册（不想放扫描目录时用）
     *
     * @param class-string<CallbackTransformer> $class
     */
    public static function register(string $class): void
    {
        static::$manual[] = $class;

        // 让下一次 all() 重新扫描
        static::$classes = null;
    }

    /**
     * key => 类
     *
     * @return array<string, class-string<CallbackTransformer>>
     */
    public static function all(): array
    {
        if (static::$classes !== null) {
            return static::$classes;
        }

        $found = [];

        foreach (array_merge(static::$manual, static::scan()) as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (! is_subclass_of($class, CallbackTransformer::class)) {
                continue;
            }

            $key = $class::key();

            if ($key === '') {
                continue;
            }

            // 后扫到的覆盖先扫到的，方便在别的模块里覆盖内置转换器
            $found[$key] = $class;
        }

        // 默认转换器必须在，且不能被覆盖掉
        $found[DefaultTransformer::key()] = DefaultTransformer::class;

        return static::$classes = $found;
    }

    /**
     * @return array<int, class-string<CallbackTransformer>>
     */
    protected static function scan(): array
    {
        $classes = [];

        foreach ((array) config('transformers.paths', []) as $namespace => $path) {
            $path = (string) $path;

            if (! is_dir($path)) {
                continue;
            }

            foreach (glob($path . '/*.php') ?: [] as $file) {
                $classes[] = rtrim((string) $namespace, '\\') . '\\' . basename($file, '.php');
            }
        }

        return $classes;
    }

    public static function has(?string $key): bool
    {
        return $key !== null && $key !== '' && isset(static::all()[$key]);
    }

    /**
     * 后台下拉用
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (static::all() as $key => $class) {
            $options[] = [
                'value' => $key,
                'label' => $class::label(),
                'description' => $class::description(),
            ];
        }

        // 默认排最前，其余按名称
        usort($options, function (array $a, array $b) {
            if ($a['value'] === DefaultTransformer::key()) {
                return -1;
            }

            if ($b['value'] === DefaultTransformer::key()) {
                return 1;
            }

            return strcmp($a['label'], $b['label']);
        });

        return $options;
    }

    /**
     * 取转换器：key 为空、或类已经被删掉时回退到默认
     *
     * 这样「正在使用的模板被删了」不会让整条回调发不出去。
     */
    public function resolve(?string $key): CallbackTransformer
    {
        $classes = static::all();

        if ($key !== null && $key !== '' && isset($classes[$key])) {
            return app($classes[$key]);
        }

        if ($key !== null && $key !== '' && ! isset($classes[$key])) {
            logger()->warning('回调功能配置的转换器已不存在，已回退到默认', [
                'transformer' => $key,
                'available' => array_keys($classes),
            ]);
        }

        return app(DefaultTransformer::class);
    }

    /**
     * 测试/后台刷新用：强制重新扫描
     */
    public static function flush(): void
    {
        static::$classes = null;
    }
}
