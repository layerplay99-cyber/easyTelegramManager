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
 * WebHookController / SlashCommandDispatcher / Bots 模型里，
 * 各自从 api_token 或 config 取 token，难以统一（例如以后要加代理、异步、超时、
 * 统一的异常处理）。
 *
 * 现在所有「取一个 bot 客户端」的地方都走这里：
 *   app(BotApiFactory::class)->forBot($bot);                  // 从 Bots 模型
 *   app(BotApiFactory::class)->forToken($token);              // 已知 token
 *
 * 新增 Bot 侧功能时，直接注入/解析本工厂即可拿到可用的 $api，不用再关心 token 来源。
 *
 * 本项目是多 bot 多商户，**没有也不需要有全局兜底 bot**：每个机器人的 token 都在
 * bmbots.api_token 上，由调用方按业务场景（订单、绑定、会话）确定用哪个。
 *
 * base url 统一读 config('telegram.base_bot_url')：部署了本地 Telegram Bot API
 * Server（TDLib）时只要改 .env 的 TELEGRAM_BASE_BOT_URL，全项目一起切换，
 * 不用改代码。详见 docs/telegram-bot-api-server.md。
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

        return new Api($token, false, null, $this->baseBotUrl());
    }

    /**
     * Bot API 基础地址（要写到 /bot 这一级）
     *
     * ⚠️ 不能只依赖 config('telegram.base_bot_url') 生效：SDK 的 Api 只有在走
     * BotsManager（Telegram::bot()）时才会读这个配置，直接 new Api() 是读不到的，
     * 所以这里显式作为第 4 个参数传进去。取不到配置时返回 null，让 SDK 用官方地址。
     */
    protected function baseBotUrl(): ?string
    {
        $url = trim((string) config('telegram.base_bot_url', ''));

        return $url !== '' ? $url : null;
    }
}
