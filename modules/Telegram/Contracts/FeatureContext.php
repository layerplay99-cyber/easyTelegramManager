<?php

declare(strict_types=1);

namespace Modules\Telegram\Contracts;

use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\Features;
use Telegram\Bot\Api;

/**
 * 功能执行上下文
 *
 * 把「功能配置 + 命令参数 + 该作用域已存的数据」打包传给 Driver，
 * Driver 不需要自己查库、不需要解析 update，也不需要 new Api()。
 */
class FeatureContext
{
    public function __construct(
        public readonly Features $feature,
        public readonly array $config,
        public readonly array $args = [],
        public readonly array $params = [],
        public readonly array $stored = [],
        public readonly array $payload = [],
        public readonly ?Bots $bot = null,
        public readonly ?Api $telegram = null,
        public readonly int|string|null $chatId = null,
        public readonly ?int $userId = null,
        public readonly string $command = '',
        public readonly string $requestId = '',
    ) {
    }

    /**
     * 取第 $index 个命令参数
     */
    public function arg(int $index, mixed $default = null): mixed
    {
        return $this->args[$index] ?? $default;
    }

    /**
     * 按参数名取值：优先命令实参，其次该作用域已存数据
     *
     * 绑定类功能常用：/bm 123 → 存 merchant_id=123，
     * 后续「查余额」命令无需再带参数，可直接引用已存的 {{merchant_id}}。
     */
    public function value(string $name, mixed $default = null): mixed
    {
        foreach ($this->params as $i => $p) {
            if (($p['name'] ?? null) === $name && isset($this->args[$i])) {
                return $this->args[$i];
            }
        }

        return $this->stored[$name] ?? $default;
    }

    /**
     * 取配置项，支持 a.b.c 路径
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * 取触发载荷字段（webhook 场景为回调 JSON 原文）
     */
    public function payload(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, $key, $default);
    }
}