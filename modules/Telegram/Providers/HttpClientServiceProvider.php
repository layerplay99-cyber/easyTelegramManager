<?php

declare(strict_types=1);

namespace Modules\Telegram\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

/**
 * 出站 HTTP 的全局默认选项
 *
 * 为什么需要它：本项目里所有 Http::xxx() 都只设了总超时（timeout），没有任何一处设
 * 连接超时。Guzzle 的 connect_timeout 默认是 0（无限等待），所以对端建连卡住时，
 * 进程会一直空等，直到总超时才抛 cURL error 28 —— 期间队列 worker 被整条占住。
 * 并发一上来，单个任务的等待会被排队放大成整体超时。
 *
 * 这里只注入「默认值」：
 *   · connect_timeout 是纯新增，任何调用点显式传的 timeout 依然优先，不影响既有行为；
 *   · 不在这里设 timeout，避免把 CatchAdmin 核心里那些本就需要长超时的调用一起压短。
 *     总超时由各调用点通过 ThirdEndpointResolver::normalizeTimeout() 收口。
 *
 * 注意：Laravel 的 Http:: facade 每次调用都会 new 一个 GuzzleHttp\Client，
 * 所以「连接复用」没法在这里做，必须改造调用点用容器里的单例 Client 才行。
 *
 * @see \Illuminate\Http\Client\Factory::globalOptions()
 * @see \Modules\Telegram\Services\Feature\ThirdEndpointResolver::normalizeTimeout()
 */
class HttpClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Http::globalOptions([
            'connect_timeout' => (int) config('telegram.http_client.connect_timeout', 3),
        ]);
    }
}
