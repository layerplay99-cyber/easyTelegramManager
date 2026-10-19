<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureDriver;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\InteractiveSession;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Feature\TemplateRenderer;
use Modules\Telegram\Services\Feature\UpstreamCaller;
use Modules\Telegram\Services\InteractiveSessionService;
use Modules\Telegram\Services\OperatorPolicy;

/**
 * 交互按钮点击处理（callback_query 触发）
 *
 * 按钮里只有 code + act，点进来后按下面顺序逐条校验，任何一条不过都不执行：
 *   1) code 能在会话表里查到（随机串，猜不到）
 *   2) 会话的 chat_id 与当前群一致（按钮被转发到别的群点 → 拒绝）
 *   3) 会话的 bot_id 与当前机器人一致（多 bot 下防止串机器人）
 *   4) 未过期（默认 24h，后台可调）
 *   5) 当前用户在允许名单里（默认群管理员，/trsq 可改）
 *   6) 动作码在本次会话的按钮范围内（防止自己拼一个 callback_data）
 *   7) claim() 原子抢锁（两个人同时点、Telegram 重投，只有一次会真正提交上游）
 *
 * 需要二次确认时：按钮配置里带 confirm，第一次点击只把消息改成确认界面，
 * 再点「确认」才真正发请求。
 *
 * ---------------------------------------------------------------------------
 * 后台怎么配（代码里搜不到谁在 new 这个类，它是配置驱动的）
 * ---------------------------------------------------------------------------
 *
 * 一、建功能
 *   功能列表 → 新建
 *     执行器   ：交互按钮点击处理
 *     触发方式 ：callback_query
 *
 * 二、绑定（漏了这步点了没反应）
 *   把这个功能绑定到「机器人 + 群」。分发是按 bot_id + chat_id 找绑定的，
 *   没绑就进不到这里；而且本驱动对不是自家前缀的按钮是静默忽略的，不会报错。
 *
 * 三、配套的发按钮那头
 *   回调功能（执行器「接收三方推送」）+ 消息处理选转换器
 *   （内置的 trade_confirm 就是发「确认 / 驳回」按钮的参考实现）。
 *   两边动作码要对上：转换器发 confirm / reject，下面的 branches 就得写
 *   confirm / reject 作为 key。
 *
 * 四、上游（提交给谁）
 *   会话上的 third_config_id → 功能绑定上的 → 机器人上的，取第一个有值的。
 *   一般配在机器人上就够了：A 商户的机器人提交到甲上游，B 商户的到乙上游。
 *
 * 五、动作分支 branches（完整示例，直接抄改）
 * {
 *   "confirm": {
 *     "mode": "submit",
 *     "endpoint_code": "merchant.trade.submit",
 *     "method": "POST",
 *     "sign": true,
 *     "sign_algo": "hmac_sha256",
 *     "params": {
 *       "merchant_id": "{{session.merchant_id}}",
 *       "trade_no": "{{payload.trade_no}}",
 *       "action": "confirm"
 *     },
 *     "success_template": "✅ 已提交：{{payload.trade_no}}",
 *     "fail_template": "❌ 提交失败：{{error}}（可重试）",
 *     "confirm": {
 *       "text": "确认提交？",
 *       "buttons": [{"text": "确认", "act": "yes"}, {"text": "取消", "act": "no"}]
 *     }
 *   },
 *   "reject": {"mode": "cancel", "reply_template": "已驳回 {{payload.trade_no}}"},
 *   "note":   {"mode": "reply",  "reply_template": "已记录备注"}
 * }
 *
 * mode 三种：
 *   submit  调上游（要 endpoint_code，接口在后台「平台接口」里配）
 *   reply   只回群消息，不调上游
 *   cancel  作废会话，不调上游
 *
 * 模板变量：
 *   {{payload.xxx}}        回调原文（会话里存的那份）
 *   {{payload.trade_no}}
 *   {{session.code}} {{session.merchant_id}} {{session.chat_id}} {{session.step}}
 *   {{merchant_id}} {{trade_no}} {{act}}
 *   {{operator.id}} {{operator.username}}   谁点的
 *   {{response.xxx}} {{error}}              提交后才有的上游返回 / 错误信息
 *
 * 提交失败时会 release 会话锁（允许重试，重试次数累加），成功才置 DONE 并撤按钮。
 */
class InteractiveClickDriver implements FeatureDriver
{
    public function __construct(
        protected InteractiveSessionService $sessions,
        protected OperatorPolicy $policy,
        protected UpstreamCaller $upstream,
        protected TemplateRenderer $renderer,
    ) {
    }

    public static function key(): string
    {
        return 'interactive.click';
    }

    public static function label(): string
    {
        return '交互按钮点击处理';
    }

    public static function group(): string
    {
        return '交互功能';
    }

    public static function triggers(): array
    {
        return ['callback_query'];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'branches',
                'label' => '动作分支',
                'type' => 'textarea',
                'required' => true,
                'hint' => 'JSON，key 是按钮的动作码（act）。示例：'
                    . '{"confirm":{"mode":"submit","endpoint_code":"merchant.trade.submit","method":"POST",'
                    . '"sign":true,"sign_algo":"hmac_sha256",'
                    . '"params":{"merchant_id":"{{session.merchant_id}}","trade_no":"{{payload.trade_no}}","action":"confirm"},'
                    . '"success_template":"✅ 已提交：{{payload.trade_no}}","fail_template":"❌ 提交失败：{{error}}",'
                    . '"confirm":{"text":"确认提交？","buttons":[{"text":"确认","act":"yes"},{"text":"取消","act":"no"}]}},'
                    . '"reject":{"mode":"cancel","reply_template":"已驳回 {{payload.trade_no}"}},'
                    . '"note":{"mode":"reply","reply_template":"已记录备注"}}。'
                    . 'mode：submit（调上游，需 endpoint_code）/ reply（只回复）/ cancel（作废）；'
                    . 'method 留空用接口自带的；sign=false 可关闭签名；'
                    . 'success_template / fail_template 优先于 reply_template；'
                    . 'confirm 为二次确认，第一次点只改消息，再点「确认」才提交。'
                    . '模板变量见类注释：{{payload.xxx}} / {{session.merchant_id}} / {{trade_no}} / {{act}} / {{operator.username}} / {{error}}',
            ],
            [
                'key' => 'remove_buttons',
                'label' => '处理后撤掉按钮',
                'type' => 'switch',
                'required' => false,
                'default' => true,
                'hint' => '防止同一个按钮被反复点',
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

        if (empty($config['branches'])) {
            $errors[] = '必须配置动作分支';
        }

        return $errors;
    }

    public function execute(FeatureContext $context): FeatureResult
    {
        $data = (string) ($context->callbackData() ?? '');

        // 不是我们家的按钮（别的功能的按钮），保持安静，别在群里添乱
        if (! str_starts_with($data, InteractiveStartDriver::DATA_PREFIX)) {
            return FeatureResult::ok([], ['ignored' => true]);
        }

        [$code, $act] = array_pad(
            explode(':', substr($data, strlen(InteractiveStartDriver::DATA_PREFIX)), 2),
            2,
            ''
        );

        $session = $this->sessions->findByCode($code);

        if (! $session) {
            return $this->quiet('该操作已失效');
        }

        // ---- 归属校验：防串群 / 串机器人 ----
        if ((string) $session->chat_id !== (string) $context->chatId) {
            return $this->quiet('这个按钮不属于当前群');
        }

        if ($session->bot_id && $context->bot && (int) $session->bot_id !== (int) $context->bot->id) {
            return $this->quiet('这个按钮不属于当前机器人');
        }

        // ---- 过期 ----
        if ($session->isExpired()) {
            $this->sessions->finish($session, InteractiveSession::STATUS_EXPIRED);

            return $this->quiet('该操作已过期，请重新发起');
        }

        // ---- 权限：默认群管理员，/trsq 可指定 ----
        $allowed = is_array($session->allowed) && $session->allowed !== []
            ? $session->allowed
            : $this->policy->settings((string) $session->chat_id);

        if (! $this->policy->allows($allowed, (string) $session->chat_id, $context->userId, $context->username(), $context->bot)) {
            return $this->quiet('你没有权限操作这个按钮', true);
        }

        $branches = $this->parseBranches($context->config('branches'));
        $buttons = is_array($session->buttons) ? $session->buttons : [];

        // ---- 二次确认阶段：只认 confirm 里声明过的动作码 ----
        if ((int) $session->step > 0) {
            $pending = (string) $session->pending_act;
            $confirm = $this->parseConfirm($branches[$pending] ?? []);

            if (in_array($act, $confirm['yes'], true)) {
                return $this->run($context, $session, $branches[$pending] ?? [], $pending);
            }

            if (in_array($act, $confirm['no'], true)) {
                if (! $this->sessions->claim($session)) {
                    return $this->quiet('该操作已被处理');
                }

                $this->sessions->finish($session, InteractiveSession::STATUS_CANCELLED);

                return $this->terminate($context, $session, '已取消', '已取消');
            }

            return $this->quiet('无效操作');
        }

        // ---- 动作码合法性：只能点这次会话里出现过的按钮 ----
        if (! $this->hasAct($buttons, $act) && ! isset($branches[$act])) {
            return $this->quiet('无效操作');
        }

        // ---- 需要二次确认的按钮：先改成确认界面，不提交 ----
        $button = $this->findButton($buttons, $act);

        if (! empty($button['confirm'])) {
            if (! $this->sessions->claim($session)) {
                return $this->quiet('该操作已被处理');
            }

            $this->sessions->hold($session, 1, $act);

            return $this->askConfirm($session, $button, $act);
        }

        return $this->run($context, $session, $branches[$act] ?? [], $act);
    }

    /**
     * 真正执行：调上游 → 回复 → 撤按钮
     *
     * @param array<string, mixed> $branch
     */
    private function run(FeatureContext $context, InteractiveSession $session, array $branch, string $act): FeatureResult
    {
        $mode = (string) ($branch['mode'] ?? 'submit');

        $data = $this->buildData($session, $act, $context);

        // 抢锁：并发点击 / Telegram 重投只有一次能进来
        if (! $this->sessions->claim($session)) {
            return $this->quiet('该操作已被处理');
        }

        if ($mode === 'cancel') {
            $this->sessions->finish($session, InteractiveSession::STATUS_CANCELLED);

            return $this->terminate(
                $context,
                $session,
                $this->render((string) ($branch['reply_template'] ?? '已取消'), $data),
                '已取消'
            );
        }

        if ($mode === 'reply') {
            $this->sessions->finish($session, InteractiveSession::STATUS_DONE);

            return $this->terminate(
                $context,
                $session,
                $this->render((string) ($branch['reply_template'] ?? '已处理'), $data),
                '已处理'
            );
        }

        // ---- submit：调上游 ----
        $third = $this->resolveUpstream($session, $context);
        $endpointCode = (string) ($branch['endpoint_code'] ?? '');

        if (! $third) {
            $this->sessions->release($session);

            return $this->quiet('尚未配置承接该业务的上游，请在后台「功能配置」里选择上游', true);
        }

        if ($endpointCode === '') {
            $this->sessions->release($session);

            return $this->quiet('该动作没有配置提交接口（endpoint_code）', true);
        }

        $response = $this->upstream->call($endpointCode, $third, $data, [
            'params' => (array) ($branch['params'] ?? []),
            'method' => (string) ($branch['method'] ?? ''),
            'sign' => (bool) ($branch['sign'] ?? true),
            'sign_algo' => (string) ($branch['sign_algo'] ?? 'hmac_sha256'),
        ]);

        $data['response'] = $response['body'] ?? null;
        $data['error'] = $response['error'];

        if ($response['success']) {
            $this->sessions->finish($session, InteractiveSession::STATUS_DONE, $response, $context->userId, $context->username());

            $text = $this->render(
                (string) ($branch['success_template'] ?? $branch['reply_template'] ?? '✅ 提交成功'),
                $data
            );

            return $this->terminate($context, $session, $text, '提交成功');
        }

        // 上游失败：回滚成待处理，允许重试（次数已累加）
        $this->sessions->release($session);

        $text = $this->render(
            (string) ($branch['fail_template'] ?? '❌ 提交失败：{{error}}'),
            $data
        );

        return $this->terminate($context, $session, $text, '提交失败', false);
    }

    /**
     * 结束：回群消息 + 应答点击 + 撤按钮
     */
    private function terminate(
        FeatureContext $context,
        InteractiveSession $session,
        string $text,
        string $answerText,
        bool $success = true
    ): FeatureResult {
        $result = $success
            ? FeatureResult::reply($text, ['session_id' => $session->id])
            : FeatureResult::fail($text);

        $result = $result->answerCallback($answerText);

        // 撤按钮：只改 reply_markup，不动正文（editMessageText 传空正文会被 Telegram 拒）
        if ($context->config('remove_buttons', true)) {
            $result = $result->withMeta([
                'edit_message' => ['remove_markup' => true, 'message_id' => $session->message_id],
            ]);
        }

        if ($context->config('parse_mode')) {
            $result = $result->withMeta(['parse_mode' => (string) $context->config('parse_mode')]);
        }

        return $result;
    }

    /**
     * 二次确认界面：把原消息改成确认文案 + 确认/取消按钮
     *
     * @param array<string, mixed> $button
     */
    private function askConfirm(InteractiveSession $session, array $button, string $act): FeatureResult
    {
        $confirm = $this->parseConfirm(['confirm' => $button['confirm']]);
        $data = $this->buildData($session, $act, null);

        $keyboard = [];

        foreach ($confirm['buttons'] as $item) {
            $callbackData = InteractiveStartDriver::DATA_PREFIX . $session->code . ':' . $item['act'];

            if (strlen($callbackData) <= 64) {
                $keyboard[] = ['text' => $item['text'], 'callback_data' => $callbackData];
            }
        }

        $text = $this->render(
            (string) (is_array($button['confirm']) ? ($button['confirm']['text'] ?? '确认执行该操作？') : '确认执行该操作？'),
            $data
        );

        return FeatureResult::ok(['confirming' => true])
            ->answerCallback('')
            ->editMessage($text, ['inline_keyboard' => [$keyboard]], $session->message_id);
    }

    /**
     * 不回群消息，只应答点击（权限不足/已过期/串群等，都不该打扰群）
     */
    private function quiet(string $reason, bool $alert = false): FeatureResult
    {
        return FeatureResult::ok(['skipped' => $reason])
            ->answerCallback($reason, $alert);
    }

    /**
     * @param array<string, mixed> $branch
     * @return array{buttons:array<int,array<string,string>>,yes:array<int,string>,no:array<int,string>}
     */
    private function parseConfirm(array $branch): array
    {
        $raw = $branch['confirm'] ?? null;

        $buttons = [];

        if (is_array($raw) && ! empty($raw['buttons'])) {
            foreach ((array) $raw['buttons'] as $item) {
                if (is_array($item)) {
                    $buttons[] = [
                        'text' => (string) ($item['text'] ?? '确认'),
                        'act' => (string) ($item['act'] ?? 'yes'),
                        'yes' => (bool) ($item['yes'] ?? in_array((string) ($item['act'] ?? 'yes'), ['yes', 'ok', 'confirm'], true)),
                    ];
                } else {
                    $buttons[] = ['text' => (string) $item, 'act' => (string) $item, 'yes' => true];
                }
            }
        }

        if (! $buttons) {
            $buttons = [
                ['text' => '确认', 'act' => 'yes', 'yes' => true],
                ['text' => '取消', 'act' => 'no', 'yes' => false],
            ];
        }

        return [
            'buttons' => $buttons,
            'yes' => array_values(array_column(array_filter($buttons, fn ($b) => $b['yes']), 'act')),
            'no' => array_values(array_column(array_filter($buttons, fn ($b) => ! $b['yes']), 'act')),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseBranches(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $json = json_decode((string) $raw, true);

        return is_array($json) ? $json : [];
    }

    /**
     * @param array<int, array<string, mixed>> $buttons
     */
    private function hasAct(array $buttons, string $act): bool
    {
        foreach ($buttons as $button) {
            if ((string) ($button['act'] ?? '') === $act) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $buttons
     * @return array<string, mixed>
     */
    private function findButton(array $buttons, string $act): array
    {
        foreach ($buttons as $button) {
            if ((string) ($button['act'] ?? '') === $act) {
                return is_array($button) ? $button : [];
            }
        }

        return [];
    }

    private function resolveUpstream(InteractiveSession $session, FeatureContext $context): ?ThirdApiConfig
    {
        $id = (int) ($session->third_config_id ?: $context->bind?->third_config_id ?: $context->bot?->third_config_id);

        return $id > 0 ? ThirdApiConfig::query()->find($id) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildData(InteractiveSession $session, string $act, ?FeatureContext $context): array
    {
        return [
            'payload' => is_array($session->payload) ? $session->payload : [],
            'session' => [
                'code' => $session->code,
                'merchant_id' => $session->merchant_id,
                'chat_id' => $session->chat_id,
                'step' => $session->step,
            ],
            'merchant_id' => (string) $session->merchant_id,
            'trade_no' => (string) data_get(is_array($session->payload) ? $session->payload : [], 'trade_no', ''),
            'act' => $act,
            'operator' => [
                'id' => $context?->userId,
                'username' => $context?->username(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $template, array $data): string
    {
        return $this->renderer->render($template, $data, []);
    }
}
