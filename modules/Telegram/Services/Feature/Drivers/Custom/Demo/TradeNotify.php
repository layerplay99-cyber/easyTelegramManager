<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Demo;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\InteractiveSessionService;
use Modules\Telegram\Services\MerchantRouteService;
use Modules\Telegram\Services\OperatorPolicy;

/**
 * 【Demo】交易通知：上游回调 → 验签 → 群里发确认按钮
 *
 * 这是「业务开发参考模板」的两分之二（另一半是 TradeConfirm）。
 * 照着这个骨架改，就能接任意上游：换签名算法改 TradeSigner，换字段改这里。
 *
 * 接入姿势：
 *   1) 后台新建功能 → 执行器选「[示例]交易通知」→ 生成 hook token
 *   2) 把 https://你的域名/api/hooks/{token} 配给上游
 *   3) 功能配置里填签名密钥（或选一个上游，用它的密钥）
 *   4) 群里 /trbd 商户号 绑定，/trsq 指定谁能点
 *
 * 如果你的上游就是标准规则（除 sign 外非空字段 ksort 后 HMAC-SHA256），
 * 可以连这个类都不用写：后台把密钥填进 hook 的「验签密钥」，
 * 执行器选通用的「交易通知 → 群内按钮」即可。
 */
class TradeNotify extends BaseCustomFeature
{
    /**
     * 按钮前缀，点击侧（TradeConfirm）据此识别是自己家的按钮
     */
    public const DATA_PREFIX = 't:';

    public static function key(): string
    {
        return 'custom.demo.trade_notify';
    }

    public static function label(): string
    {
        return '[示例]交易通知（验签）';
    }

    public static function featureName(): string
    {
        return '[示例]交易通知';
    }

    public static function featureDescription(): string
    {
        return '接收上游交易回调，验签通过后发带确认按钮的消息到绑定的群';
    }

    public static function category(): string
    {
        return 'bot';
    }

    public static function group(): string
    {
        return '示例';
    }

    /**
     * 这是被 HTTP 回调触发的功能，不是群里的命令
     */
    public static function triggerName(): string
    {
        return 'webhook';
    }

    public static function triggers(): array
    {
        return ['webhook'];
    }

    public static function defaultConfig(): array
    {
        return [
            'merchant_field' => 'merchant_id',
            'trade_no_field' => 'trade_no',
            'sign_algo' => 'hmac_sha256',
            'sign_ttl' => 300,
            'ttl_seconds' => 86400,
            'message_template' => "【交易待确认】\n商户：{{merchant_id}}\n单号：{{trade_no}}\n金额：{{payload.amount}} {{payload.currency}}",
        ];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'secret',
                'label' => '签名密钥',
                'type' => 'text',
                'required' => false,
                'hint' => '和上游约定的那把密钥。留空则用所选上游的「密钥」',
            ],
            [
                'key' => 'upstream_id',
                'label' => '承接上游',
                'type' => 'select',
                'source' => 'third_api_configs',
                'required' => false,
                'hint' => '用来取密钥和回调地址，不填则用机器人上的默认上游',
            ],
            [
                'key' => 'sign_algo',
                'label' => '签名算法',
                'type' => 'select',
                'source' => 'sign_algos',
                'required' => false,
                'default' => 'hmac_sha256',
            ],
            [
                'key' => 'sign_ttl',
                'label' => '时间窗口（秒）',
                'type' => 'number',
                'required' => false,
                'default' => 300,
                'hint' => 'timestamp 超出这个偏差就认为是重放',
            ],
            [
                'key' => 'merchant_field',
                'label' => '回调里的商户号字段',
                'type' => 'text',
                'required' => true,
                'default' => 'merchant_id',
            ],
            [
                'key' => 'trade_no_field',
                'label' => '回调里的单号字段',
                'type' => 'text',
                'required' => true,
                'default' => 'trade_no',
            ],
            [
                'key' => 'fallback_chat_id',
                'label' => '兜底群ID',
                'type' => 'text',
                'required' => false,
                'hint' => '商户号没绑定时发到这个群，留空则丢弃',
            ],
            [
                'key' => 'message_template',
                'label' => '消息模板',
                'type' => 'template',
                'required' => true,
                'default' => "【交易待确认】\n商户：{{merchant_id}}\n单号：{{trade_no}}\n金额：{{payload.amount}} {{payload.currency}}",
            ],
            [
                'key' => 'buttons',
                'label' => '按钮',
                'type' => 'textarea',
                'required' => false,
                'hint' => '[{"text":"确认","act":"confirm"},{"text":"驳回","act":"reject"}]',
            ],
            [
                'key' => 'ttl_seconds',
                'label' => '按钮有效期（秒）',
                'type' => 'number',
                'required' => false,
                'default' => 86400,
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        // HookController 已经把 $request->all() 放进 payload
        $payload = $context->payload;

        // ---- 第 1 步：验签（不知道密钥的人伪造不了，改一个字段也验不过）----
        $secret = $this->secret($context);

        if ($secret === '') {
            return FeatureResult::fail('未配置签名密钥，请在功能配置里填 secret 或选择上游');
        }

        $sign = isset($payload['sign']) && is_scalar($payload['sign']) ? (string) $payload['sign'] : null;

        if (! TradeSigner::verify($payload, $secret, $sign, (string) $context->config('sign_algo', 'hmac_sha256'))) {
            // 注意：不要在这里回群消息（webhook 场景本来也不会发），记日志即可
            return FeatureResult::fail('签名校验失败');
        }

        // ---- 第 2 步：防重放（密钥没泄露，但同一条合法通知可以被重播）----
        $ttl = (int) ($context->config('sign_ttl') ?: 300);

        if (! TradeSigner::fresh($payload['timestamp'] ?? null, $ttl)) {
            return FeatureResult::fail('请求已过期');
        }

        $nonce = (string) ($payload['nonce'] ?? '');

        if (TradeSigner::nonceSeen((string) $context->feature->id, $nonce, $ttl * 2)) {
            return FeatureResult::ok(['duplicate' => true]);
        }

        // ---- 第 3 步：定位发到哪个群（多商户多 bot 全靠这张表）----
        $merchantId = trim((string) data_get($payload, (string) $context->config('merchant_field', 'merchant_id'), ''));
        $route = $merchantId !== '' ? app(MerchantRouteService::class)->resolve($merchantId) : null;

        $chatId = $route?->chat_id ?: (string) $context->config('fallback_chat_id', '');

        if ($chatId === '') {
            return FeatureResult::fail('商户号 ' . $merchantId . ' 未绑定任何群');
        }

        // ---- 第 4 步：建会话（按钮只带 code，业务数据留服务端）----
        $tradeNo = trim((string) data_get($payload, (string) $context->config('trade_no_field', 'trade_no'), ''));

        $sessions = app(InteractiveSessionService::class);

        $dedup = md5($context->feature->id . '|' . $merchantId . '|' . $tradeNo);
        $existing = $sessions->findByDedupKey($dedup);

        if ($existing && $existing->isOpen()) {
            return FeatureResult::ok(['duplicate' => true, 'session_id' => $existing->id]);
        }

        $session = $sessions->create([
            'business_type' => 'trade',
            'feature_id' => $context->feature->id,
            'bot_id' => (int) ($context->bot?->id ?? 0),
            'chat_id' => $chatId,
            'merchant_id' => $merchantId !== '' ? $merchantId : null,
            'third_config_id' => $this->upstreamId($context),
            'payload' => $payload,
            'buttons' => $this->buttons($context->config('buttons')),
            'allowed' => app(OperatorPolicy::class)->settings($chatId),
            'dedup_key' => $dedup,
            'expires_at' => time() + (int) ($context->config('ttl_seconds') ?: 86400),
        ]);

        // ---- 第 5 步：发带按钮的消息 ----
        $text = $this->render((string) $context->config('message_template', ''), [
            'payload' => $payload,
            'merchant_id' => $merchantId,
            'trade_no' => $tradeNo,
        ], $context);

        $keyboard = [];

        foreach ($session->buttons as $button) {
            $callbackData = self::DATA_PREFIX . $session->code . ':' . $button['act'];

            if (strlen($callbackData) <= 64) { // Telegram 硬限制
                $keyboard[] = ['text' => $button['text'], 'callback_data' => $callbackData];
            }
        }

        $sent = $context->telegram?->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => ['inline_keyboard' => [$keyboard]],
        ]);

        $messageId = is_object($sent) ? ($sent->message_id ?? null) : ($sent['message_id'] ?? null);

        if ($messageId) {
            $session->message_id = (string) $messageId;
            $session->save();
        }

        return FeatureResult::ok([
            'session_id' => $session->id,
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * 取签名密钥：功能配置 > 所选上游 > 机器人默认上游
     */
    private function secret(FeatureContext $context): string
    {
        $direct = (string) $context->config('secret', '');

        if ($direct !== '') {
            return $direct;
        }

        $id = $this->upstreamId($context);

        return $id ? (string) (ThirdApiConfig::query()->find($id)?->secrept_key ?? '') : '';
    }

    private function upstreamId(FeatureContext $context): ?int
    {
        $id = (int) ($context->config('upstream_id') ?: $context->bind?->third_config_id ?: $context->bot?->third_config_id);

        return $id > 0 ? $id : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buttons(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        if (! $items) {
            $items = [
                ['text' => '确认', 'act' => 'confirm'],
                ['text' => '驳回', 'act' => 'reject'],
            ];
        }

        $out = [];

        foreach ($items as $item) {
            $text = (string) ($item['text'] ?? '');
            $act = (string) ($item['act'] ?? '');

            if ($text !== '' && $act !== '') {
                $out[] = ['text' => $text, 'act' => $act];
            }
        }

        return $out;
    }
}
