<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Upstream;

/**
 * 出站请求描述（适配器 prepareRequest 的输入 / 输出）
 *
 * 这是一个可变对象：适配器直接在上面改，改完由调用方发给 Http。
 * 「平台标准」在这里体现为 params 用标准名（merchant_id / trade_id / amount …），
 * 适配器负责把它们翻译成厂商的私有名字。
 *
 * 字段分工（别混）：
 *   params  业务参数，GET 时进 query、POST 时进 body
 *   query   平台/上游配置里的固定 query（headers/query 两个 json 列）
 *   pathParams 已被 URL 路径占位消耗掉的参数名，发请求时要从 query 里剔除
 *   headers 请求头（token 由调用方统一加，适配器可再加厂商私有头）
 */
class UpstreamRequest
{
    /**
     * GET 时把业务参数放进请求体（post-data），URL 不带 query。
     * 只给明确需要的上游接口开，默认 false = 参数拼在 URL 上，行为不变。
     */
    public bool $paramsInBody = false;

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @param array<int, string> $pathParams
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $endpointCode = '',
        public string $method = 'GET',
        public string $path = '',
        public string $url = '',
        public array $params = [],
        public array $query = [],
        public array $headers = [],
        public array $pathParams = [],
        public int $timeout = 15,
        public string $bodyMode = 'json',
        public array $extra = []
    ) {
    }

    /**
     * 批量改参数名：['merchant_id' => 'mchId', 'trade_id' => 'orderNo']
     * 只改存在的键；值原样保留。
     *
     * @param array<string, string> $map 标准名 => 厂商名
     */
    public function renameParams(array $map): static
    {
        $renamed = [];

        foreach ($this->params as $key => $value) {
            $renamed[$map[$key] ?? $key] = $value;
        }

        $this->params = $renamed;

        // 路径占位消耗的参数也要跟着改名，否则这些参数会又进一遍 query
        foreach ($this->pathParams as &$name) {
            $name = $map[$name] ?? $name;
        }
        unset($name);

        return $this;
    }

    /**
     * 删除一个业务参数（比如它已被挪进路径占位 / 请求头，不想再出现在 query / body 里）
     */
    public function removeParam(string $key): static
    {
        unset($this->params[$key]);

        return $this;
    }

    /**
     * 增加 / 覆盖一个业务参数（固定值、时间戳、签名字段都用它）
     */
    public function setParam(string $key, mixed $value): static
    {
        $this->params[$key] = $value;

        return $this;
    }

    public function hasParam(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }

    /**
     * 把某个业务参数挪进请求头（有些厂商要求 token / 商户号放 header）
     * 挪走后不会再出现在 query / body 里。
     */
    public function moveParamToHeader(string $param, string $header): static
    {
        if ($this->hasParam($param)) {
            $this->headers[$header] = (string) $this->params[$param];
            unset($this->params[$param]);
        }

        return $this;
    }

    /**
     * 批量把业务参数挪进 URL query（上游要求某些参数必须挂在 URL 上时用）。
     * 挪走后不会再出现在 body 里；发请求时与固定 query 一起拼进 URL。
     *
     * @param array<int, string> $keys
     */
    public function moveParamsToQuery(array $keys): static
    {
        foreach ($keys as $key) {
            if ($this->hasParam($key)) {
                $this->query[$key] = $this->params[$key];
                unset($this->params[$key]);
            }
        }

        return $this;
    }

    public function putHeader(string $key, string $value): static
    {
        $this->headers[$key] = $value;

        return $this;
    }

    public function setMethod(string $method): static
    {
        $this->method = strtoupper($method);

        return $this;
    }

    public function setBodyMode(string $mode): static
    {
        $this->bodyMode = $mode === 'form' ? 'form' : 'json';

        return $this;
    }

    /**
     * 声明这个接口走「GET + 参数在 post-data」：URL 不带 query，参数进请求体。
     * 配合 setBodyMode('form') 就是常见的 form-data；默认 json。
     */
    public function sendParamsInBody(bool $enabled = true): static
    {
        $this->paramsInBody = $enabled;

        return $this;
    }

    /** 进请求体的参数：未被 URL 路径占位消耗的 params + 固定 query */
    public function bodyParams(): array
    {
        return array_merge($this->queryParams(), $this->query);
    }

    public function encodedBody(): string
    {
        $params = $this->bodyParams();

        return $this->bodyMode === 'form'
            ? http_build_query($params)
            : (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function bodyContentType(): string
    {
        return $this->bodyMode === 'form'
            ? 'application/x-www-form-urlencoded'
            : 'application/json';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * 最终进 query 的业务参数（剔掉已被路径占位消耗的）
     *
     * @return array<string, mixed>
     */
    public function queryParams(): array
    {
        return array_diff_key($this->params, array_fill_keys($this->pathParams, true));
    }

    /**
     * 适配器自己存点中间结果（例如原始订单号，parseResponse 时还要用）
     */
    public function remember(string $key, mixed $value): static
    {
        $this->extra[$key] = $value;

        return $this;
    }

    public function recalled(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }
}
