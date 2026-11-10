<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream;

use Illuminate\Support\Facades\File;
use Modules\Telegram\Contracts\UpstreamAdapter;
use Modules\Telegram\Services\Upstream\Adapters\DefaultAdapter;
use Symfony\Component\Finder\SplFileInfo;

/**
 * 上游协议适配器注册表
 *
 * 与「回调消息转换器」同一套范式：扫描目录 → 反射过滤 → key => 类 → app() 取实例。
 * 不做跨请求缓存，开发者丢一个新类进目录，后台重新打开「三方配置」就能看到。
 *
 * 默认适配器（直通）永远存在且不可被覆盖，key 为空 / 类被删掉时回退到它，
 * 这样「正在用的插件被删了」不会让整条业务链路发不出去。
 */
class UpstreamAdapterRegistry
{
    /**
     * @var array<string, class-string<UpstreamAdapter>>|null
     */
    protected static ?array $classes = null;

    /**
     * 手动注册的插件：register() 写进来，与扫描结果一起参与 all()。
     * 给特殊场景留口子（插件不在扫描目录下、或运行时才确定类名）。
     *
     * @var array<string, class-string<UpstreamAdapter>>
     */
    protected static array $manual = [];

    /**
     * @return array<string, class-string<UpstreamAdapter>>
     */
    public static function all(): array
    {
        if (static::$classes !== null) {
            return static::$classes;
        }

        $found = [];

        foreach (static::scan() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (! is_subclass_of($class, UpstreamAdapter::class)) {
                continue;
            }

            $key = $class::key();

            if ($key === '') {
                continue;
            }

            // 后扫到的覆盖先扫到的，方便别的模块覆盖内置适配器
            $found[$key] = $class;
        }

        // 直通基线必须在，且不能被覆盖
        $found[DefaultAdapter::key()] = DefaultAdapter::class;

        return static::$classes = array_merge(static::$manual, $found);
    }

    /**
     * 手动注册一个插件（一般用不到：插件放进扫描目录即可被发现）。
     * 同名 key 会覆盖扫描结果，便于外部替换实现。
     *
     * @param class-string<UpstreamAdapter> $class
     */
    public static function register(string $key, string $class): void
    {
        if (! is_subclass_of($class, UpstreamAdapter::class)) {
            throw new \InvalidArgumentException("{$class} 未实现 UpstreamAdapter");
        }

        static::$manual[$key] = $class;
        static::$classes = null;
    }

    /**
     * 递归扫描 config/upstream_adapters.php 里配的目录
     *
     * 用递归是为了让示例可以按子目录组织（Adapters/Demo/*.php），
     * 相对路径会换算成命名空间后缀。
     *
     * @return array<int, class-string<UpstreamAdapter>>
     */
    protected static function scan(): array
    {
        $classes = [];

        foreach ((array) config('upstream_adapters.paths', []) as $namespace => $path) {
            $path = (string) $path;

            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace(['/', '\\'], '\\', $file->getRelativePathname());
                $relative = preg_replace('/\.php$/i', '', (string) $relative);

                $classes[] = rtrim((string) $namespace, '\\') . '\\' . $relative;
            }
        }

        return $classes;
    }

    /**
     * 后台下拉用
     *
     * demo=true 表示教学示例（放在 Adapters\Demo 命名空间下），
     * 后台会打上「示例」标记，提醒别在生产环境选中。
     *
     * @return array<int, array{value: string, label: string, description: string, demo: bool}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (static::all() as $key => $class) {
            $options[] = [
                'value' => $key,
                'label' => $class::label(),
                'description' => $class::description(),
                'demo' => str_contains($class, '\\Demo\\'),
            ];
        }

        // 默认排最前，其余按名称
        usort($options, function (array $a, array $b) {
            if ($a['value'] === DefaultAdapter::key()) {
                return -1;
            }

            if ($b['value'] === DefaultAdapter::key()) {
                return 1;
            }

            return strcmp($a['label'], $b['label']);
        });

        return $options;
    }

    /**
     * 某个 key 是否已注册（含手动注册与扫描结果）
     */
    public static function has(string $key): bool
    {
        return $key !== '' && isset(static::all()[$key]);
    }

    /**
     * 清空解析缓存（测试 / 热替换插件类时用）
     */
    public static function flush(): void
    {
        static::$classes = null;
    }

    /**
     * 取适配器：key 为空、或类已被删掉时回退到直通基线
     */
    public function resolve(?string $key): UpstreamAdapter
    {
        $classes = static::all();

        if ($key !== null && $key !== '' && isset($classes[$key])) {
            return app($classes[$key]);
        }

        if ($key !== null && $key !== '' && ! isset($classes[$key])) {
            logger()->warning('三方配置的上游适配器已不存在，已回退到直通', [
                'adapter' => $key,
                'available' => array_keys($classes),
            ]);
        }

        return app(DefaultAdapter::class);
    }

    /**
     * 按上游实例取适配器
     *
     * 这是运行时的统一入口：所有出站调用点只需要拿到 ThirdApiConfig，
     * 传进来即可，不用各自判断字段。
     */
    public function forUpstream(mixed $upstream): UpstreamAdapter
    {
        return $this->resolve($upstream?->adapter ?: null);
    }
}
