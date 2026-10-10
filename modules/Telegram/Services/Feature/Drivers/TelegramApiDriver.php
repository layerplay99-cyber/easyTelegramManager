<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureDriver;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Jobs\TelegramApiOperateFeatureJob;
use Modules\Telegram\Models\TelegramApiUsers;
use Illuminate\Support\Facades\Cache;

/**
 * 真人账号驱动（Telegram API / MTProto）
 *
 * 与「机器人」是两条互不相干的链路：
 *   - 机器人（bot token）受 Telegram 限制，管不了群，主要用于做交互功能
 *     （命令 / 按钮 / 回调 → http.request、feature.store、callback.op …）；
 *   - 真人（自己申请 app_id / app_hash + 登录 session）才能群发、私聊、@、踢人、快捷回复、采集表情。
 *
 * 本驱动只做真人这一条：按 payload 里的 api_user_id / app_id 找到真人账号，
 * 把 chatIds 逐个拆成 TelegramApiOperateFeatureJob 派发（带重试 / 超时 / 回执）。
 * 两条链路共用同一套「驱动 + 定义 + 配置」标准，只是具体实现不同。
 *
 * 后台配置：method（要做的操作）+ message_type（发送类操作的消息类型）。
 * 运行时参数由 payload 传入：chatIds / text / mediaPath / buttons / user_id / reply_to_msg_id / mention_ids。
 */
class TelegramApiDriver implements FeatureDriver
{
    /**
     * 真人可执行的操作（后台下拉）
     *
     * 对应 Features/Definitions 下的 realman.* 定义。
     */
    public const METHODS = [
        'send' => '群发消息（多群）',
        'sendToPerson' => '私发消息（指定用户）',
        'sendToGroupPerson' => '群内@发送（@指定成员）',
        'reply' => '快捷回复（回复指定消息）',
        'kick' => '踢除群员',
        'collectEmoji' => '群表情包采集',
    ];

    /**
     * 发送的消息类型（群发 / 私发 / @发送 用）
     */
    public const MESSAGE_TYPES = [
        'text' => '文本消息',
        'media' => '媒体（图片/文件）',
    ];

    /**
     * 后台下拉选项：操作
     *
     * @return array<int, array<string, string>>
     */
    public static function methodOptions(): array
    {
        $options = [];

        foreach (self::METHODS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * 后台下拉选项：消息类型
     *
     * @return array<int, array<string, string>>
     */
    public static function messageTypeOptions(): array
    {
        $options = [];

        foreach (self::MESSAGE_TYPES as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    public static function key(): string
    {
        return 'telegram.api';
    }

    public static function label(): string
    {
        return '真人账号操作';
    }

    public static function group(): string
    {
        return '真人功能';
    }

    public static function triggers(): array
    {
        return ['manual', 'webhook', 'command'];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'method',
                'label' => '操作',
                'type' => 'select',
                'source' => 'telegram_methods',
                'required' => true,
                'default' => 'send',
                'hint' => '真人账号要执行的动作',
            ],
            [
                'key' => 'message_type',
                'label' => '消息类型',
                'type' => 'select',
                'source' => 'realman_message_types',
                'required' => false,
                'default' => 'text',
                'hint' => '仅群发 / 私发 / @发送 生效；调用方传了 message_type 时以其为准',
            ],
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        $method = (string) ($config['method'] ?? '');

        if ($method === '') {
            return ['必须选择要执行的操作'];
        }

        if (! isset(self::METHODS[$method])) {
            $errors[] = '不支持的操作：' . $method;
        }

        return $errors;
    }

    public function execute(FeatureContext $context): FeatureResult
    {
        $user = $this->resolveApiUser($context);

        if (! $user) {
            return FeatureResult::fail('未找到可执行的真人账号（payload 需带 api_user_id 或 app_id）');
        }

        $method = (string) $context->config('method', 'send');
        $payload = $context->payload;
        $chatIds = $this->normalizeChatIds($payload['chatIds'] ?? null);

        if ($chatIds === []) {
            return FeatureResult::fail('chatIds 为空');
        }

        if ($method === 'collectEmoji') {
            Cache::put(
                'telegram_group_collect_emojis_' . $chatIds[0],
                ['appId' => $user->app_id, 'session_file' => $user->session_file],
                86400 * 30
            );

            return FeatureResult::ok(
                ['chat_id' => $chatIds[0]],
                ['method' => $method, 'count' => 1]
            );
        }

        [$type, $required] = match ($method) {
            'kick' => ['kick', ['user_id']],
            'reply' => ['reply', ['reply_to_msg_id']],
            default => [$this->messageType($context), []],
        };

        foreach ($required as $param) {
            if (empty($payload[$param])) {
                return FeatureResult::fail("缺少必填参数：{$param}");
            }
        }

        // 群内@发送：mention_ids 缺省时退化成单个 user_id
        if ($method === 'sendToGroupPerson') {
            $mentionIds = $this->normalizeMentionIds($payload['mention_ids'] ?? ($payload['user_id'] ?? []));

            if ($mentionIds === []) {
                return FeatureResult::fail('缺少必填参数：mention_ids');
            }

            $payload['mention_ids'] = $mentionIds;
        }

        $sendId = isset($payload['send_id']) ? (int) $payload['send_id'] : null;

        foreach ($chatIds as $chatId) {
            $item = $payload;
            $item['chat_id'] = $chatId;
            unset($item['chatIds']);

            TelegramApiOperateFeatureJob::dispatch(
                (string) $user->session_file,
                (int) $user->app_id,
                (string) $user->app_hash,
                $item,
                $type,
                $sendId,
            );
        }

        return FeatureResult::ok(
            ['count' => count($chatIds)],
            ['method' => $method, 'type' => $type, 'count' => count($chatIds)]
        );
    }

    /**
     * 取真人账号：优先 api_user_id，其次 app_id
     */
    protected function resolveApiUser(FeatureContext $context): ?TelegramApiUsers
    {
        $id = $context->payload('api_user_id');

        if ($id) {
            return TelegramApiUsers::query()->find((int) $id);
        }

        $appId = $context->payload('app_id');

        return $appId ? TelegramApiUsers::query()->where('app_id', $appId)->first() : null;
    }

    /**
     * 消息类型：调用方传入 > 后台配置
     */
    protected function messageType(FeatureContext $context): string
    {
        $type = (string) ($context->payload('message_type') ?: $context->config('message_type') ?: 'text');

        return isset(self::MESSAGE_TYPES[$type]) ? $type : 'text';
    }

    /**
     * @return array<int, mixed>
     */
    protected function normalizeChatIds(mixed $chatIds): array
    {
        if (is_array($chatIds)) {
            return array_values(array_filter($chatIds, fn ($v) => $v !== null && $v !== ''));
        }

        return ($chatIds === null || $chatIds === '') ? [] : [$chatIds];
    }

    /**
     * @return array<int, int>
     */
    protected function normalizeMentionIds(mixed $mentionIds): array
    {
        return array_values(array_filter(array_map('intval', (array) $mentionIds)));
    }
}
