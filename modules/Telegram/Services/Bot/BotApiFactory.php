<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Bot;

use Modules\Telegram\Models\Bots;
use Telegram\Bot\Api;

/**
 * Bot API 客户端工厂
 *
 * 统一创建 Telegram Bot SDK 客户端（Telegram\Bot\Api）。
 *
 * 之前 new Api($token) 散落在 NotificationService / ListenBotInGroupService /
 * BotGroupSyncService / WebHookController / SlashCommandDispatcher / Bots 模型里，
 * 各自从 api_token 或 config 取 token，难以统一（例如以后要加代理、异步、超时、
 * 统一的异常处理）。
 *
 * 现在所有「取一个 bot 客户端」的地方都走这里：
 *   app(BotApiFactory::class)->forBot($bot);                  // 从 Bots 模型
 *   app(BotApiFactory::class)->forToken($token);              // 已知 token
 *   app(BotApiFactory::class)->default();                     // config('telegram.bot_token')
 *
 * 新增 Bot 侧功能时，直接注入/解析本工厂即可拿到可用的 $api，不用再关心 token 来源。
 */
class BotApiFactory
{
    public function forBot(Bots $bot): Api
    {
        return $this->forToken($bot->api_token);
    }

    public function forToken(?string $token): Api
    {
        if (empty($token)) {
            throw new \InvalidArgumentException('Telegram bot token is empty');
        }

        return new Api($token);
    }

    public function default(): ?Api
    {
        $token = config('telegram.bot_token');

        return $token ? new Api($token) : null;
    }
}
