<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

use Illuminate\Support\Facades\File;
use Modules\Telegram\Contracts\EndpointProvider;

/**
 * 平台接口规范（接入标准）
 *
 * 这是平台对外发布的「接入标准」：上游厂商按这里的定义实现接口即可接入，
 * 平台侧无需任何代码改动。
 *
 * 定义不写在这个文件里——**谁要用接口，谁自己声明**：
 * 在 Driver / 功能类 / Gateway 上实现 EndpointProvider 接口并返回 endpoints()，
 * 这里扫描 Services/ 目录把所有声明汇总起来。新增一类接口 = 在自己的类里加一条
 * + 跑一次同步命令，不用动本文件，也不会出现几百行的大数组。
 *
 * 设计要点（三层解耦）：
 *   features 功能            全局唯一，不含任何上游信息，只引用 code
 *   endpoints 接口规范       平台维护，含统一入参/出参
 *   thirdapi_config 上游实例 每个用户/机器人一份，只有 base_url + token 不同
 *
 * 入参 params_schema 声明「平台统一参数」，出参 response_schema 声明「统一返回字段」，
 * 不同上游的路径可以不同（path_template），但参数语义一致。
 */
class PlatformEndpointRegistry
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    protected static ?array $definitions = null;

    /**
     * 平台标准接口定义（汇总自所有 EndpointProvider）
     *
     * code            平台唯一标识，功能按它引用
     * name            中文名
     * method          HTTP 方法
     * path_template   路径模板（可用 {param} 占位，会按 params 取值替换）
     * params_schema   统一入参规范
     * response_schema 统一出参规范
     * remark          说明
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $definitions = [];

        foreach (self::providers() as $class) {
            foreach ($class::endpoints() as $key => $definition) {
                // 允许只写值不写键，此时用 definition 里的 code
                $code = (string) ($definition['code'] ?? $key);
                $definition['code'] = $code;

                $definitions[$code] = $definition;
            }
        }

        return self::$definitions = $definitions;
    }

    /**
     * 所有声明了平台接口的类
     *
     * @return array<int, class-string<EndpointProvider>>
     */
    public static function providers(): array
    {
        $providers = [];

        foreach (self::scan() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            if (! in_array(EndpointProvider::class, class_implements($class) ?: [], true)) {
                continue;
            }

            $providers[] = $class;
        }

        return $providers;
    }

    /**
     * 清空缓存（扫描结果按请求缓存，新增接口后调用）
     */
    public static function flush(): void
    {
        self::$definitions = null;
    }

    /**
     * 扫描 Services/ 目录
     *
     * @return array<int, string>
     */
    protected static function scan(): array
    {
        // __DIR__ = Services/Feature，上一层即整个 Services
        $classes = [];

        foreach (File::allFiles(dirname(__DIR__)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $classes[] = 'Modules\\Telegram\\Services\\'
                . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        }

        return $classes;
    }

    /**
     * 取单个定义
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $code): ?array
    {
        return self::definitions()[$code] ?? null;
    }

    /**
     * 同步到数据库（幂等：按 code upsert，不覆盖后台改过的启用状态）
     *
     * @return array<int, string> 同步的 code 列表
     */
    public static function sync(): array
    {
        $codes = [];

        foreach (self::definitions() as $definition) {
            \Modules\Telegram\Models\ThirdApiEndpoints::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'method' => $definition['method'],
                    'path_template' => $definition['path_template'],
                    'params_schema' => $definition['params_schema'],
                    'response_schema' => $definition['response_schema'],
                    'headers' => null,
                    'query' => null,
                    'timeout' => 30,
                    'enabled' => true,
                    'remark' => $definition['remark'] ?? null,
                ]
            );

            $codes[] = $definition['code'];
        }

        return $codes;
    }

    /**
     * 后台下拉选项：code + 名称 + 方法路径
     *
     * @return array<int, array<string, mixed>>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::definitions() as $definition) {
            $options[] = [
                'value' => $definition['code'],
                'label' => sprintf('%s（%s %s）', $definition['name'], $definition['method'], $definition['path_template']),
            ];
        }

        return $options;
    }
}