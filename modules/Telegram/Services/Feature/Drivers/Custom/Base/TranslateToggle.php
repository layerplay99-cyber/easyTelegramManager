<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Base;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\GroupConfigs;

/**
 * 开启/关闭群内自动翻译（/fy start|stop）
 *
 * 开启后，群内任何纯文本消息都会被翻译成该群绑定的语种（bot_groups.settings.language）
 * 后回发到群里；实际翻译在 PlainChatHandler，这里只负责开关。
 * 后台「群编辑」里也有同一个开关，两处等效。
 */
class TranslateToggle extends AbstractBaseFeature
{
    public static function key(): string
    {
        return 'custom.base.translate_toggle';
    }

    public static function featureName(): string
    {
        return '群内自动翻译开关';
    }

    public static function featureDescription(): string
    {
        return '开启后群内发送的任何文字内容都翻译成该群绑定的语种。用法：/fy start 或 /fy stop';
    }

    public static function uuid(): string
    {
        return 'translateToggle';
    }

    public static function commands(): array
    {
        return [
            [
                'command' => '/fy',
                'usage' => '/fy start|stop',
                'description' => '开启/关闭群内自动翻译',
                'params' => [
                    ['name' => 'action', 'required' => true, 'pattern' => 'start|stop|on|off'],
                ],
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $chatId = $context->chatId;

        if ($chatId === null || $chatId === '') {
            return FeatureResult::fail('请在群里使用该命令');
        }

        $action = strtolower(trim((string) $context->arg(0, '')));

        if (! in_array($action, ['start', 'stop', 'on', 'off'], true)) {
            return FeatureResult::reply("参数错误，用法：/fy start 或 /fy stop");
        }

        $enable = in_array($action, ['start', 'on'], true);

        $config = GroupConfigs::query()->firstOrCreate(
            ['groupId' => $this->groupIdByChat($chatId)],
            []
        );

        $config->forceFill(['translate_enabled' => $enable])->save();
        GroupConfigs::forgetCacheForChat($chatId);

        return FeatureResult::reply($enable
            ? '群内自动翻译已开启：此后发送的文字内容会翻译成群语种。'
            : '群内自动翻译已关闭。');
    }

    /**
     * chat_id → bot_groups.id（群配置以 bot_groups.id 为外键）
     */
    protected function groupIdByChat(int|string $chatId): ?int
    {
        $group = \Modules\Telegram\Models\BotGroups::query()
            ->where('chat_id', (string) $chatId)
            ->first();

        return $group?->id;
    }
}
