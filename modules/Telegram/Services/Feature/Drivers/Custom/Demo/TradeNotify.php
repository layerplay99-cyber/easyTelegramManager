<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Demo;

use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\InteractiveSessionService;
use Modules\Telegram\Services\MerchantRouteService;
use Modules\Telegram\Services\OperatorPolicy;

/**
 * 【Demo】交易通知：上游回调 → 限流 → 头签名验签 → 群里发确认按钮
 *
 * 这是「业务开发参考模板」的两分之二（另一半是 TradeConfirm）。
 * 照着这个骨架改，就能接任意上游。
 *
 * 接入姿势：
 *   1) 后台「三方配置」里点「生成密钥」，拿到 access_key / secret_key 下发给上游
 *   2) 后台新建功能 → 执行器选「[示例]交易通知」→ 生成 hook token
 *   3) 把 https://你的域名/api/hooks/{token} 配给上游，
 *      上游回调带 X-Access-Key / X-Timestamp / X-Nonce / X-Signature 请求头
 *   4) 群里 /trbd 商户号 绑定，/trsq 指定谁能点
 *
 * 入站验签已由 validate.upstream 中间件统一完成（头签名 + HMAC-SHA256，
 * 密钥 = 三方配置下发的 access_key/secret_key），这里不再重复验签。
 * 限流按来源 IP（rate_limit:ip:trade_notify:{ip}），在业务处理前先拦高频。
 */
class TradeNotify extends BaseCustomFeature
{
    /**
     * 按钮前缀，点击侧（TradeConfirm）据此识别是自己家的按钮
     */
    public const DATA_PREFIX = 't:';

    /** 入站回调验签要用上游密钥，虽不发出站 HTTP，仍属于上游相关功能 */
    public static function needsUpstream(): bool
    {
        return true;
    }

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
            'ttl_seconds' => 86400,
            'message_template' => "【交易待确认】\n商户：{{merchant_id}}\n单号：{{trade_no}}\n金额：{{payload.amount}} {{payload.currency}}",
        ];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'upstream_id',
                'label' => '承接上游',
                'type' => 'select',
                'source' => 'third_api_configs',
                'required' => false,
                'hint' => '建会话时记录的上游（关联三方配置），验签密钥走该上游下发的 access_key/secret_key，不填则用机器人上的默认上游',
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
        // HookController 已经把 $request->all() 放进 payload；
        // 入站验签已由 validate.upstream 中间件统一完成，这里只做业务。

        $payload = $context->payload;

        // ---- 第 1 步：IP 限流（业务级兜底，挡高频）----
        if (! $this->passIpRateLimit((string) request()->ip())) {
            return FeatureResult::fail('请求过于频繁，请稍后再试');
        }

        // ---- 第 2 步：定位发到哪个群（多商户多 bot 全靠这张表）----
        $merchantId = trim((string) data_get($payload, (string) $context->config('merchant_field', 'merchant_id'), ''));
        $route = $merchantId !== '' ? app(MerchantRouteService::class)->resolve($merchantId) : null;

        $chatId = $route?->chat_id ?: (string) $context->config('fallback_chat_id', '');

        if ($chatId === '') {
            return FeatureResult::fail('商户号 ' . $merchantId . ' 未绑定任何群');
        }

        // ---- 第 3 步：建会话（按钮只带 code，业务数据留服务端）----
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

        // ---- 第 4 步：发带按钮的消息 ----
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
     * 按来源 IP 限流（沿用 SecurityService 的 counter 思路）
     *
     * 同一 IP 每分钟最多 $maxAttempts 次，超了直接拒。
     */
    private function passIpRateLimit(string $ip, int $maxAttempts = 60, int $decayMinutes = 1): bool
    {
        $key = 'rate_limit:ip:trade_notify:' . $ip;
        $attempts = (int) Cache::get($key, 0);

        if ($attempts >= $maxAttempts) {
            return false;
        }

        Cache::put($key, $attempts + 1, $decayMinutes * 60);

        return true;
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
