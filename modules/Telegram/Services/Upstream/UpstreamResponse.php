<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream;

/**
 * 上游原始响应（适配器 parseResponse 的输入）
 *
 * 只做「如实记录」，不做任何业务判断：
 *   - transportError 非空表示请求根本没发出去 / 超时，此时 httpStatus 可能为 0
 *   - httpStatus 是 HTTP 状态码；很多厂商业务失败也返回 200，靠 body 里的 code 判断
 *   - json 是解析后的数组，body 是原始字符串；json 为 null 表示不是合法 JSON
 *
 * 适配器请用 isTransportOk() / isHttpOk() / payload() 取值，
 * 不要自己重复解析 $body。
 */
class UpstreamResponse
{
    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $httpStatus = 0,
        public string $body = '',
        public ?array $json = null,
        public array $headers = [],
        public string $transportError = ''
    ) {
    }

    public static function fromHttp(mixed $response): static
    {
        $json = $response->json();

        // 上游返回 JSON 标量（如 "ok"）也是合法响应。
        // 原调用方用 (array) $json 接收，这里保持一致，不能当成「不是 JSON」。
        if ($json !== null && ! is_array($json)) {
            $json = (array) $json;
        }

        return new static(
            httpStatus: $response->status(),
            body: (string) $response->body(),
            json: $json,
            headers: $response->headers(),
        );
    }

    public static function transportFailed(string $error): static
    {
        return new static(transportError: $error);
    }

    public function isTransportOk(): bool
    {
        return $this->transportError === '';
    }

    public function isHttpOk(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    public function isJson(): bool
    {
        return $this->json !== null;
    }

    /**
     * 响应体数组；非 JSON 时给一个 _raw 让上层能看出返回了什么
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->json ?? ['_raw' => $this->body];
    }

    /**
     * 取响应字段，支持点路径：data.balance / currency.code / result.list.0.amount
     */
    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->payload(), $path, $default);
    }
}
