<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\ApiHookServices;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Telegram\Services\LogMessageService;

class HookService
{
    private const DEFAULT_API_URL = 'https://api.dayang888.top';
    private const API_TRACK_ENDPOINT = '/api/v3/webhook/track';

    protected ClientRequest $clientRequest;

    public function __construct(
        protected LogMessageService $logMessageService
    ) {}

    /**
     * 检查商户订单号
     */
    public function checkMerchantOrderNo(string $id, int|string $userId): bool
    {
        $url = $this->buildApiUrl(self::API_TRACK_ENDPOINT);

        $response = Http::acceptJson()
            ->withToken(config('hook.api_token_new'))
            ->beforeSending(function ($request) {
                $this->clientRequest = $request;
            })
            ->post($url, [
                'out_trade_no' => $id,
                'out_merchant_id' => $userId,
            ]);

        $this->logRequest($response, 'checkMerchantOrderNo');

        return $response->successful();
    }

    /**
     * 恢复订单
     */
    public function recover(string $id, string $code, int|string $userId, array $remark = []): Response
    {
        $url = $this->buildApiUrl(self::API_TRACK_ENDPOINT);

        $postData = [
            'out_trade_no' => $id,
            'code' => $code,
            'out_merchant_id' => $userId,
            'remark' => $remark,
        ];

        if ($id === '') {
            unset($postData['out_trade_no']);
        }

        $response = Http::acceptJson()
            ->withToken(config('hook.api_token_new'))
            ->beforeSending(function ($request) {
                $this->clientRequest = $request;
            })
            ->post($url, $postData);

        $this->logRequest($response, 'recover');

        return $response;
    }

    /**
     * 发送 GET 请求
     */
    public function get(string $url, array $apiConfig, array|string|null $query = null): Response
    {
        return Http::baseUrl($apiConfig['HOOK_API_URL'])
            ->acceptJson()
            ->withToken($apiConfig['HOOK_API_TOKEN'])
            ->get($url, $query);
    }

    /**
     * 发送 POST 请求
     */
    public function post(string $url, array $apiConfig, array $data = []): Response
    {
        return Http::baseUrl($apiConfig['HOOK_API_URL'])
            ->acceptJson()
            ->withToken($apiConfig['HOOK_API_TOKEN'])
            ->post($url, $data);
    }

    /**
     * 构建 API URL
     */
    private function buildApiUrl(string $endpoint): string
    {
        $baseUrl = config('hook.api_url_new', self::DEFAULT_API_URL);
        return sprintf('%s%s', $baseUrl, $endpoint);
    }

    /**
     * 记录请求日志
     */
    private function logRequest(Response $response, string $action): void
    {
        $this->logMessageService->createLaravelLog(
            'hook',
            [
                'action' => $action,
                'request' => [
                    'url' => $this->clientRequest->url(),
                    'method' => $this->clientRequest->method(),
                    'data' => $this->clientRequest->body(),
                ],
                'response' => [
                    'data' => $response->body(),
                    'status' => $response->status(),
                ],
            ]
        );
    }
}
