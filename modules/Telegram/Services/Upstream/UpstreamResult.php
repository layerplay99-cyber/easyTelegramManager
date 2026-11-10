<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream;

/**
 * 平台标准结果（适配器 parseResponse 的输出）
 *
 * 这是整套插件机制的「标准」落点：不管上游返回 available_funds / balance /
 * result.data.amount，适配器都要把它归一成平台约定的 data 结构，
 * 功能代码和回复模板只读 data，永远不用关心上游是谁。
 *
 * 字段：
 *   success     业务是否成功（HTTP 2xx + 厂商业务码都通过才算）
 *   message     失败时给业务看的原因，会变成 _error 回给功能
 *   code        厂商业务码，排查用
 *   data        归一化后的平台标准结构（模板 / 功能代码的数据源）
 *   raw         上游原始响应，排查 / 审计用，模板里用 _raw 可取
 *   httpStatus  HTTP 状态码
 */
class UpstreamResult
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public bool $success,
        public array $data = [],
        public array $raw = [],
        public string $message = '',
        public int|string|null $code = null,
        public int $httpStatus = 0
    ) {
    }

    /**
     * @param array<string, mixed> $data 平台标准结构
     * @param array<string, mixed>|null $raw 不传则等于 data
     */
    public static function ok(array $data, ?array $raw = null, int|string|null $code = null, int $httpStatus = 200): static
    {
        return new static(
            success: true,
            data: $data,
            raw: $raw ?? $data,
            code: $code,
            httpStatus: $httpStatus
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fail(
        string $message,
        array $raw = [],
        int|string|null $code = null,
        int $httpStatus = 0
    ): static {
        return new static(
            success: false,
            data: [],
            raw: $raw,
            message: $message,
            code: $code,
            httpStatus: $httpStatus
        );
    }

    /**
     * 取标准字段，支持点路径：data.balance / data.currency / status
     */
    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->data, $path, $default);
    }

    public function isOk(): bool
    {
        return $this->success;
    }

    /**
     * 转成现有 callEndpoint() 的返回约定，保证零破坏
     *
     * - 失败：['_error' => 原因]，功能侧的 isset($data['_error']) 判断照旧生效
     * - 成功：归一化后的 data，另附 _raw 供模板/排查取原始报文
     *
     * DefaultAdapter 的 data 就是原始响应本身，因此接插件前后返回值等价。
     *
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        if (! $this->success) {
            $out = ['_error' => $this->message !== '' ? $this->message : '上游请求失败'];

            if ($this->raw !== []) {
                // 传输层失败时上游原文会单装在 ['_raw' => body] 里，
                // 这里摊平一层，排查时直接看到错误页文本，而不是 {"_raw":{"_raw":"..."}}。
                $out['_raw'] = count($this->raw) === 1 && array_key_exists('_raw', $this->raw)
                    ? $this->raw['_raw']
                    : $this->raw;
            }

            return $out;
        }

        $out = $this->data;

        // 只有适配器真的改写过结构时才附 _raw。
        // DefaultAdapter 的 data === raw，返回值与接插件之前逐字节等价（零破坏）。
        if ($this->raw !== $this->data && ! array_key_exists('_raw', $out)) {
            $out['_raw'] = $this->raw;
        }

        return $out;
    }
}
