<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

/**
 * 模板渲染器
 *
 * 让「三方接口返回值 → 消息文本」这一步不需要写代码。
 * 支持两种占位符：
 *   {{path.to.value}}  从数据数组里取值（响应体 / 载荷）
 *   {{@param}}         取命令参数或已存数据（简化写法，绑定类功能常用）
 *
 * 例：余额：{{data.balance}} 元
 */
class TemplateRenderer
{
    /**
     * @param array<string, mixed> $data 数据源（通常是三方响应体）
     * @param array<string, mixed> $extra 额外数据（命令参数、已存数据等）
     */
    public function render(string $template, array $data = [], array $extra = []): string
    {
        if ($template === '') {
            return '';
        }

        $source = $this->flatten($data, $extra);

        $rendered = preg_replace_callback(
            '/\{\{\s*([^{}]+?)\s*\}\}/',
            function (array $m) use ($source) {
                $path = trim($m[1]);

                if (str_starts_with($path, '@')) {
                    return $this->stringify($source[substr($path, 1)] ?? null);
                }

                return $this->stringify(data_get($source, $path));
            },
            $template
        );

        return (string) $rendered;
    }

    /**
     * 把嵌套数组打平成 key => value，便于 {{@key}} 直接取
     */
    private function flatten(array $data, array $extra = []): array
    {
        $flat = [];

        array_walk_recursive($extra, function ($value, $key) use (&$flat) {
            $flat[$key] = $value;
        });

        return array_merge($flat, $data);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? '是' : '否';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}