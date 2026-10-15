<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Callback;

use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Services\Feature\Drivers\InteractiveStartDriver;
use Modules\Telegram\Services\Feature\TemplateRenderer;
use Modules\Telegram\Services\InteractiveSessionService;
use Modules\Telegram\Services\OperatorPolicy;

/**
 * 交给转换器的上下文
 *
 * 转换器只读这里的数据，不做查询、不发消息——发送由驱动统一做，
 * 这样「用哪个机器人的 token、发到哪个群」的决定权始终在系统手里。
 */
class TransformerContext
{
    public function __construct(
        public readonly array $payload,
        public readonly Features $feature,
        public readonly ?Bots $bot = null,
        public readonly int|string|null $chatId = null,
        /** 命中的路由键值（商户ID），没走路由表时为空 */
        public readonly string $routeValue = '',
        /** 合并后的配置：功能默认 ← 绑定覆盖 */
        public readonly array $config = [],
        /** 该作用域已存的数据（模板里的 {{xxx}}） */
        public readonly array $stored = [],
    ) {
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function payload(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, $key, $default);
    }

    /**
     * 渲染模板：默认用功能配置里的 reply_template
     */
    public function render(?string $template = null, array $extra = []): string
    {
        $template = $template ?? (string) $this->config('reply_template', '{{_raw}}');

        return app(TemplateRenderer::class)->render(
            $template,
            array_merge($this->payload, $extra),
            $this->stored
        );
    }

    /**
     * 建一次交互会话，返回可直接放进 CallbackMessage 的按钮
     *
     * 按钮点击走 InteractiveClickDriver（就是「支付二次确认」那套）：
     * 会话数据留在服务端，callback_data 里只带会话码和动作码。
     *
     * @param array $buttons [['text' => '确认', 'act' => 'confirm'], ...]
     * @return array [['text' => ..., 'callback_data' => ...], ...]
     */
    public function sessionButtons(array $buttons, string $businessType = 'callback', ?string $dedupKey = null): array
    {
        $items = [];

        foreach ($buttons as $button) {
            $text = (string) ($button['text'] ?? '');
            $act = (string) ($button['act'] ?? $button['action'] ?? '');

            if ($text !== '' && $act !== '') {
                $items[] = ['text' => $text, 'act' => $act];
            }
        }

        if (! $items) {
            return [];
        }

        $chatId = (string) $this->chatId;

        $session = app(InteractiveSessionService::class)->create([
            'business_type' => $businessType,
            'feature_id' => $this->feature->id,
            'bot_id' => (int) ($this->bot?->id ?? 0),
            'chat_id' => $chatId,
            'merchant_id' => $this->routeValue !== '' ? $this->routeValue : null,
            'payload' => $this->payload,
            'buttons' => $items,
            'allowed' => app(OperatorPolicy::class)->settings($chatId),
            'dedup_key' => $dedupKey ?: md5($this->feature->id . '|' . $this->routeValue . '|' . json_encode($this->payload)),
            'expires_at' => time() + (int) ($this->config('ttl_seconds') ?: 86400),
        ]);

        $out = [];

        foreach ($session->buttons as $item) {
            $callbackData = InteractiveStartDriver::DATA_PREFIX . $session->code . ':' . $item['act'];

            if (strlen($callbackData) <= 64) {
                $out[] = ['text' => $item['text'], 'callback_data' => $callbackData];
            }
        }

        return $out;
    }
}
