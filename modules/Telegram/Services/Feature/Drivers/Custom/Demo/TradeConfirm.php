<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Demo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\InteractiveSession;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\InteractiveSessionService;
use Modules\Telegram\Services\OperatorPolicy;

/**
 * 【Demo】交易确认：群里点按钮 → 加签 → 提交上游 → 回复群里
 *
 * 和 TradeNotify 配对使用。整套安全校验的顺序不要改：
 *   1) code 能查到会话       —— 按钮里只有随机 code，猜不到也枚举不出来
 *   2) 是本群 / 是本机器人    —— 防止按钮被拿到别的群点
 *   3) 没过期                —— 默认 24h
 *   4) 有权限                —— 默认群管理员，/trsq 可指定
 *   5) 动作码合法            —— 只能点这次会话里出现过的按钮
 *   6) claim() 抢锁          —— 两个人同时点、Telegram 重投，只提交一次
 *   7) 加签后提交            —— 上游验签通过才认
 */
class TradeConfirm extends BaseCustomFeature
{
    public static function key(): string
    {
        return 'custom.demo.trade_confirm';
    }

    public static function label(): string
    {
        return '[示例]交易确认（加签提交）';
    }

    public static function featureName(): string
    {
        return '[示例]交易确认';
    }

    public static function featureDescription(): string
    {
        return '处理群里交易按钮的点击，加签后提交给上游并回复结果';
    }

    public static function category(): string
    {
        return 'bot';
    }

    public static function group(): string
    {
        return '示例';
    }

    public static function triggerName(): string
    {
        return 'callback_query';
    }

    public static function triggers(): array
    {
        return ['callback_query'];
    }

    public static function defaultConfig(): array
    {
        return [
            'submit_path' => 'api/merchant/trade/submit',
            'sign_algo' => 'hmac_sha256',
            'success_template' => '✅ 交易已确认（单号 {{trade_no}}）',
            'fail_template' => '❌ 提交失败：{{error}}',
            'reject_template' => '已驳回（单号 {{trade_no}}）',
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
                'hint' => '和上游约定的那把密钥，留空则用所选上游的「密钥」',
            ],
            [
                'key' => 'upstream_id',
                'label' => '承接上游',
                'type' => 'select',
                'source' => 'third_api_configs',
                'required' => false,
            ],
            [
                'key' => 'submit_path',
                'label' => '提交路径',
                'type' => 'text',
                'required' => true,
                'default' => 'api/merchant/trade/submit',
                'hint' => '拼在上游 base_url 后面。想走后台可配置的接口，改用 UpstreamCaller + PlatformEndpointRegistry',
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
                'key' => 'success_template',
                'label' => '成功回复',
                'type' => 'template',
                'required' => false,
                'default' => '✅ 交易已确认（单号 {{trade_no}}）',
            ],
            [
                'key' => 'fail_template',
                'label' => '失败回复',
                'type' => 'template',
                'required' => false,
                'default' => '❌ 提交失败：{{error}}',
            ],
            [
                'key' => 'reject_template',
                'label' => '驳回回复',
                'type' => 'template',
                'required' => false,
                'default' => '已驳回（单号 {{trade_no}}）',
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $data = (string) ($context->callbackData() ?? '');

        // 不是自己家的按钮就别管，避免在群里刷无意义的消息
        if (! str_starts_with($data, TradeNotify::DATA_PREFIX)) {
            return FeatureResult::ok(['ignored' => true]);
        }

        [$code, $act] = array_pad(
            explode(':', substr($data, strlen(TradeNotify::DATA_PREFIX)), 2),
            2,
            ''
        );

        $session = app(InteractiveSessionService::class)->findByCode($code);

        if (! $session) {
            return $this->quiet('该操作已失效');
        }

        // ---- 归属 ----
        if ((string) $session->chat_id !== (string) $context->chatId) {
            return $this->quiet('这个按钮不属于当前群');
        }

        if ($session->bot_id && $context->bot && (int) $session->bot_id !== (int) $context->bot->id) {
            return $this->quiet('这个按钮不属于当前机器人');
        }

        // ---- 过期 ----
        if ($session->isExpired()) {
            app(InteractiveSessionService::class)->finish($session, InteractiveSession::STATUS_EXPIRED);

            return $this->quiet('该操作已过期');
        }

        // ---- 权限 ----
        $policy = app(OperatorPolicy::class);
        $allowed = is_array($session->allowed) && $session->allowed !== []
            ? $session->allowed
            : $policy->settings((string) $session->chat_id);

        if (! $policy->allows($allowed, (string) $session->chat_id, $context->userId, $context->username())) {
            return $this->quiet('你没有权限操作这个按钮', true);
        }

        // ---- 动作码 ----
        $acts = array_column((array) $session->buttons, 'act');

        if (! in_array($act, $acts, true)) {
            return $this->quiet('无效操作');
        }

        // ---- 抢锁 ----
        if (! app(InteractiveSessionService::class)->claim($session)) {
            return $this->quiet('该操作已被处理');
        }

        $templateData = [
            'payload' => (array) $session->payload,
            'merchant_id' => (string) $session->merchant_id,
            'trade_no' => (string) data_get((array) $session->payload, 'trade_no', ''),
        ];

        // ---- 驳回：不调上游，直接作废 ----
        if ($act === 'reject') {
            app(InteractiveSessionService::class)->finish($session, InteractiveSession::STATUS_CANCELLED, [], $context->userId, $context->username());

            return $this->done($context, $session, $this->render(
                (string) $context->config('reject_template', '已驳回'),
                $templateData,
                $context
            ), '已驳回');
        }

        // ---- 加签提交 ----
        $secret = $this->secret($context);
        $upstream = $this->upstream($context);

        if ($secret === '' || ! $upstream) {
            app(InteractiveSessionService::class)->release($session);

            return $this->quiet('未配置上游或签名密钥', true);
        }

        $params = [
            'merchant_id' => (string) $session->merchant_id,
            'trade_no' => $templateData['trade_no'],
            'action' => $act,
            'operator_id' => (string) $context->userId,
            'operator_name' => (string) $context->username(),
            // 时间戳和随机串一起参与签名，上游可据此拒绝重放
            'timestamp' => (string) time(),
            'nonce' => Str::random(16),
        ];

        // ★ 加签：把上面这些字段算出一个 sign 一起发过去
        $params['sign'] = TradeSigner::sign($params, $secret, (string) $context->config('sign_algo', 'hmac_sha256'));

        $url = rtrim((string) $upstream->api_url, '/') . '/' . ltrim((string) $context->config('submit_path', ''), '/');

        try {
            $response = Http::timeout(20)->asForm()->post($url, $params);
        } catch (\Throwable $e) {
            app(InteractiveSessionService::class)->release($session);

            return $this->done($context, $session, $this->render(
                (string) $context->config('fail_template', '❌ 提交失败：{{error}}'),
                $templateData + ['error' => $e->getMessage()],
                $context
            ), '提交失败', false);
        }

        if ($response->successful()) {
            app(InteractiveSessionService::class)->finish(
                $session,
                InteractiveSession::STATUS_DONE,
                ['http_code' => $response->status(), 'body' => $response->json()],
                $context->userId,
                $context->username()
            );

            return $this->done($context, $session, $this->render(
                (string) $context->config('success_template', '✅ 交易已确认'),
                $templateData,
                $context
            ), '提交成功');
        }

        // 上游失败：回滚成待处理，允许重试
        app(InteractiveSessionService::class)->release($session);

        return $this->done($context, $session, $this->render(
            (string) $context->config('fail_template', '❌ 提交失败：{{error}}'),
            $templateData + ['error' => '上游返回 HTTP ' . $response->status()],
            $context
        ), '提交失败', false);
    }

    /**
     * 收尾：回群消息 + 应答点击 + 撤掉按钮
     */
    private function done(
        FeatureContext $context,
        InteractiveSession $session,
        string $text,
        string $answerText,
        bool $success = true
    ): FeatureResult {
        $result = $success ? FeatureResult::reply($text) : FeatureResult::fail($text);

        return $result
            ->answerCallback($answerText)
            ->withMeta([
                'edit_message' => ['remove_markup' => true, 'message_id' => $session->message_id],
            ]);
    }

    /**
     * 只弹提示，不打扰群
     */
    private function quiet(string $reason, bool $alert = false): FeatureResult
    {
        return FeatureResult::ok(['skipped' => $reason])->answerCallback($reason, $alert);
    }

    private function secret(FeatureContext $context): string
    {
        $direct = (string) $context->config('secret', '');

        if ($direct !== '') {
            return $direct;
        }

        return (string) ($this->upstream($context)?->secrept_key ?? '');
    }

    private function upstream(FeatureContext $context): ?ThirdApiConfig
    {
        $id = (int) ($context->config('upstream_id')
            ?: $context->bind?->third_config_id
            ?: $context->bot?->third_config_id);

        return $id > 0 ? ThirdApiConfig::query()->find($id) : null;
    }
}
