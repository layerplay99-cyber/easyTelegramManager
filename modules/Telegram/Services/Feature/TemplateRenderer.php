<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature;

/**
 * 模板渲染器
 *
 * 让「三方接口返回值 → 消息文本」这一步不需要写代码。
 * 单双括号都支持，两种写法等价：
 *   {{path.to.value}} / {path.to.value}   从数据数组里取值（响应体 / 载荷），支持点路径取嵌套
 *   {{@param}}        / {@param}          取命令参数或已存数据（简化写法，绑定类功能常用）
 *
 * 例：
 *   余额：{{data.balance}} 元
 *   当前商户余额：{available_funds} {currency.code}
 *
 * 路径走 data_get()，所以上游返回嵌套结构（如 currency: {code, symbol}）时
 * 直接写 {currency.code} 即可；外面还包了一层的话写成 {data.available_funds}。
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

        // 先处理双括号，再处理单括号。
        // 分两步而不是写一个正则：单括号那一步要避开 {{ }} 的残留，
        // 双括号先跑完，剩下的 {xxx} 才是真正要替换的。
        $rendered = preg_replace_callback(
            '/\{\{\s*([^{}]+?)\s*\}\}/',
            fn (array $m) => $this->replace(trim($m[1]), $source),
            $template
        );

        // 允许 @ 前缀（{@param}）与点路径（{currency.code}）
        $rendered = preg_replace_callback(
            '/\{(@?[A-Za-z0-9_.\[\]\-]+)\}/',
            fn (array $m) => $this->replace(trim($m[1]), $source),
            (string) $rendered
        );

        return (string) $rendered;
    }

    /**
     * 替换单个占位（两种括号共用一套规则）
     */
    private function replace(string $path, array $source): string
    {
        if ($path === '') {
            return '';
        }

        // @xxx：取命令参数 / 已存数据（打平后的叶子 key）
        if (str_starts_with($path, '@')) {
            return $this->stringify($source[substr($path, 1)] ?? null);
        }

        return $this->stringify(data_get($source, $path));
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