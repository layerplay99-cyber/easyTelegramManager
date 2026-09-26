<?php

namespace Modules\Telegram\Services\ApiHookServices;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiopayService
{
    /**
     * Issue a GET request to the given URL.
     */
    public function get(string $url, string $apiUrl, string $api_token, $query = null): Response
    {
        return Http::baseUrl($apiUrl)
            ->acceptJson()
            ->withToken($api_token)
            ->get($url, $query);
    }

    /**
     * Issue a POST request to the given URL.
     */
    public function post(string $url, string $apiUrl, string $api_token, array $data = []): Response
    {
        return Http::baseUrl($apiUrl)
            ->acceptJson()
            ->withToken($api_token)
            ->post($url, $data);
    }
}
