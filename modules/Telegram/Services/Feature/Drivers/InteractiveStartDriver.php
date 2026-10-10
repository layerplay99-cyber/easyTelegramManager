<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureDriver;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\InteractiveSession;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\InteractiveSessionService;
use Modules\Telegram\Services\MerchantRouteService;
use Modules\Telegram\Services\OperatorPolicy;
use Modules\Telegram\Services\Feature\TemplateRenderer;

/**
 * 交易通知 → 群里带按钮的消息（webhook 触发）
 *
 * 上游回调 POST /api/hooks/{token}（HookController 已验签 + 幂等）→ 本驱动：
 *   1) 用回调里的商户号查 merchant_routes，定位「哪个机器人 / 哪个群 / 哪个上游」
 *      ——取代旧实现 Cache::forever('mid', chat_id)，多商户多 bot 不再互相覆盖
 *   2) 建交互会话：回调原文、权限快照、按钮布局全落库
 *   3) 发一条带内联按钮的消息，按钮里只带随机 code + 动作
 *
 * 按钮 callback_data 格式：i:{code}:{act}（约 16 字节，远低于 Telegram 的 64 字节上限）
 */
class InteractiveStartDriver implements FeatureDriver
{
    /**
     * 按钮数据前缀，点击侧据此识别「这是我们家的按钮」
     */
    public const DATA_PREFIX = 'i:';

    public function __construct(
        protected MerchantRouteService $routes,
        protected InteractiveSessionService $sessions,
        protected OperatorPolicy $policy,
        protected BotApiFactory $botApi,
        protected TemplateRenderer $renderer,
    ) {
    }

    public static function key(): string
    {
        return 'interactive.start';
    }

    public static function label(): string
    {
        return '交易通知 → 群内按钮';
    }

    public static function group(): string
    {
        return '三方回调';
    }

    public static function triggers(): array
    {
        return ['webhook'];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'merchant_field',
                'label' => '回调里的商户号字段',
                'type' => 'text',
                'required' => true,
                'default' => 'merchant_id',
                'hint' => '支持 a.b.c 路径；用它查群绑定',
            ],
            [
                'key' => 'trade_no_field',
                'label' => '回调里的单号字段',
                'type' => 'text',
                'required' => false,
                'default' => 'trade_no',
                'hint' => '用于展示与幂等（同一单号不会重复发按钮）',
            ],
            [
                'key' => 'fallback_chat_id',
                'label' => '兜底群ID',
                'type' => 'text',
                'required' => false,
                'hint' => '商户号没绑定时发到这个群（留空则丢弃并告警）',
            ],
            [
                'key' => 'message_template',
                'label' => '消息模板',
                'type' => 'template',
                'required' => true,
                'default' => '【交易通知】\n商户：{{merchant_id}}\n单号：{{trade_no}}\n金额：{{payload.amount}}',
                'hint' => '可用 {{payload.xxx}} 取回调原文字段，{{merchant_id}} {{trade_no}}',
            ],
            [
                'key' => 'buttons',
                'label' => '按钮',
                'type' => 'textarea',
                'required' => false,
                'hint' => 'JSON 数组：[{"text":"确认","act":"ok"},{"text":"驳回","act":"no"}]；'
                    . '也可一行一个写「文案|动作码」。需要二次确认就在按钮上加 "confirm":{"text":"确认提交？"}',
            ],
            [
                'key' => 'ttl_seconds',
                'label' => '按钮有效期（秒）',
                'type' => 'number',
                'required' => false,
                'default' => 86400,
            ],
            [
                'key' => 'parse_mode',
                'label' => '解析模式',
                'type' => 'select',
                'source' => 'parse_modes',
                'required' => false,
            ],
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['message_template'])) {
            $errors[] = '必须配置消息模板';
        }

        if (empty($config['merchant_field']) && empty($config['fallback_chat_id'])) {
            $errors[] = '至少要配「商户号字段」或「兜底群ID」，否则不知道发到哪';
        }

        return $errors;
    }

    public function execute(FeatureContext $context): FeatureResult
    {
        $payload = $context->payload;

        // ---- 1) 定位：商户号 → (机器人, 群, 上游) ----
        $merchantId = trim((string) data_get($payload, (string) $context->config('merchant_field', 'merchant_id'), ''));
        $route = $merchantId !== '' ? $this->routes->resolve($merchantId) : null;

        $fallback = trim((string) $context->config('fallback_chat_id', ''));

        if ($route) {
            $chatId = (string) $route->chat_id;
            $botId = (int) $route->bot_id;
            $thirdConfigId = $route->third_config_id;
        } elseif ($fallback !== '') {
            $chatId = $fallback;
            $botId = (int) ($context->bot?->id ?? 0);
            $thirdConfigId = $context->bind?->third_config_id ?? $context->bot?->third_config_id;
        } else {
            return FeatureResult::fail('回调里的商户号「' . $merchantId . '」没有绑定任何群，请先在该群执行 /trbd ' . $merchantId);
        }

        // 上游优先级：商户路由 > 绑定级 > 机器人级
        $thirdConfigId = $thirdConfigId ?: ($context->bind?->third_config_id ?? $context->bot?->third_config_id);

        // ---- 2) 幂等：同一张单据不重复发按钮 ----
        $tradeNo = trim((string) data_get($payload, (string) $context->config('trade_no_field', 'trade_no'), ''));
        $dedupKey = $merchantId !== '' || $tradeNo !== ''
            ? md5($context->feature->id . '|' . $merchantId . '|' . $tradeNo)
            : '';

        if ($dedupKey !== '') {
            $existing = $this->sessions->findByDedupKey($dedupKey);

            if ($existing && $existing->isOpen()) {
                return FeatureResult::ok(['session_id' => $existing->id], ['duplicate' => true]);
            }
        }

        // ---- 3) 建会话 ----
        $buttons = $this->parseButtons($context->config('buttons'));
        $ttl = (int) ($context->config('ttl_seconds') ?: config('hook.session_ttl', 86400));

        $session = $this->sessions->create([
            'business_type' => 'trade',
            'feature_id' => $context->feature->id,
            'bot_id' => $botId,
            'chat_id' => $chatId,
            'merchant_id' => $merchantId !== '' ? $merchantId : null,
            'third_config_id' => $thirdConfigId ? (int) $thirdConfigId : null,
            'payload' => $payload,
            'buttons' => $buttons,
            'allowed' => $this->policy->settings($chatId),
            'dedup_key' => $dedupKey !== '' ? $dedupKey : null,
            'expires_at' => time() + $ttl,
        ]);

        // ---- 4) 发消息 ----
        $data = [
            'payload' => $payload,
            'merchant_id' => $merchantId,
            'trade_no' => $tradeNo,
            'code' => $session->code,
        ];

        $text = $this->renderer->render((string) $context->config('message_template', ''), $data, []);

        $keyboard = $this->buildKeyboard($session->code, $buttons);

        if (! $keyboard) {
            return FeatureResult::fail('没有可用的按钮（配置的按钮数据为空）');
        }

        $telegram = $this->clientFor($botId, $context);

        if (! $telegram) {
            return FeatureResult::fail('找不到对应的机器人客户端');
        }

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];

        if ($context->config('parse_mode')) {
            $params['parse_mode'] = (string) $context->config('parse_mode');
        }

        try {
            $sent = $telegram->sendMessage($params);
        } catch (\Throwable $e) {
            return FeatureResult::fail('发送通知失败：' . $e->getMessage());
        }

        $messageId = $this->extractMessageId($sent);

        if ($messageId !== null) {
            $session->message_id = (string) $messageId;
            $session->save();
        }

        return FeatureResult::ok([
            'session_id' => $session->id,
            'code' => $session->code,
            'chat_id' => $chatId,
            'bot_id' => $botId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * 按机器人取客户端：商户路由上可能指定了与当前 hook 不同的机器人
     */
    private function clientFor(int $botId, FeatureContext $context): mixed
    {
        if ($botId > 0 && (int) ($context->bot?->id ?? 0) !== $botId) {
            $bot = Bots::query()->find($botId);

            return $bot ? $this->botApi->forBot($bot) : null;
        }

        return $context->telegram;
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    private function buildKeyboard(string $code, array $buttons): array
    {
        $rows = [];

        foreach ($buttons as $button) {
            $text = (string) ($button['text'] ?? '');
            $act = (string) ($button['act'] ?? '');

            if ($text === '' || $act === '') {
                continue;
            }

            $callbackData = self::DATA_PREFIX . $code . ':' . $act;

            // Telegram 硬限制 64 字节，超限的按钮直接丢弃，避免整条消息发不出去
            if (strlen($callbackData) > 64) {
                continue;
            }

            $rows[(int) ($button['row'] ?? 0)][] = [
                'text' => $text,
                'callback_data' => $callbackData,
            ];
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * 解析按钮配置：优先 JSON，其次「文案|动作码」每行一个
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseButtons(mixed $raw): array
    {
        if (is_array($raw) && $raw !== []) {
            return $this->normalizeButtons($raw);
        }

        $text = trim((string) $raw);

        if ($text === '') {
            return $this->normalizeButtons([
                ['text' => '确认', 'act' => 'ok'],
                ['text' => '驳回', 'act' => 'no'],
            ]);
        }

        $json = json_decode($text, true);

        if (is_array($json)) {
            return $this->normalizeButtons($json);
        }

        $items = [];

        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$btnText, $act] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            $items[] = ['text' => $btnText, 'act' => $act !== '' ? $act : $btnText];
        }

        return $this->normalizeButtons($items);
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeButtons(array $items): array
    {
        $out = [];

        foreach ($items as $index => $item) {
            // 兼容 keyvalue 形态：{"确认": "ok"}
            if (! is_array($item)) {
                $item = ['text' => (string) $index, 'act' => (string) $item];
            }

            $text = (string) ($item['text'] ?? $item['label'] ?? '');
            $act = (string) ($item['act'] ?? $item['action'] ?? $item['code'] ?? '');

            if ($text === '' || $act === '') {
                continue;
            }

            $out[] = [
                'text' => $text,
                'act' => $act,
                'row' => (int) ($item['row'] ?? 0),
                'confirm' => $item['confirm'] ?? null,
            ];
        }

        return $out;
    }

    private function extractMessageId(mixed $sent): ?string
    {
        if (is_array($sent)) {
            return isset($sent['message_id']) ? (string) $sent['message_id'] : null;
        }

        if (is_object($sent)) {
            foreach (['message_id', 'messageId'] as $prop) {
                if (isset($sent->{$prop})) {
                    return (string) $sent->{$prop};
                }
            }

            if (method_exists($sent, 'getMessageId')) {
                return (string) $sent->getMessageId();
            }
        }

        return null;
    }
}
