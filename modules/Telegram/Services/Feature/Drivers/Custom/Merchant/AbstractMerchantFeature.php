<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\MerchantRouteService;

/**
 * 商户类功能公共基类
 *
 * 这一组功能都是「群里发命令 → 拿本群绑定的商户号 → 调上游 → 用后台模板回复」。
 * 公共部分收在这里：上游选择、商户号解析、未绑定提示、响应变量归一化、模板渲染。
 *
 * ⚠️ 本类刻意不 implements EndpointProvider：PlatformEndpointRegistry::providers()
 *    会扫描 Services/ 下所有实现了该接口的类（包括抽象类），接口定义放在各自
 *    用到它的功能类里更清楚，删功能时定义也随之消失。
 *
 * ⚠️ 抽象类会被 CustomFeatureRegistry / DriverRegistry 的 isAbstract() 跳过，
 *    不会在后台多出一个没有命令的功能。
 */
abstract class AbstractMerchantFeature extends BaseCustomFeature
{
    protected const DEFAULT_NOT_BOUND_TIP = '本群还没有绑定商户号';

    /** 老版本默认值里把命令写死了，运行时识别后按新默认值处理 */
    private const LEGACY_NOT_BOUND_TIP = '本群还没有绑定商户号，请先执行 /bm <商户号>';

    public static function category(): string
    {
        return 'bot';
    }

    public static function group(): string
    {
        return '商户';
    }

    public static function triggerName(): string
    {
        return 'command';
    }

    public static function triggers(): array
    {
        return ['command'];
    }

    /**
     * 功能默认配置里公共的那几项
     */
    protected static function commonDefaultConfig(string $endpointCode, array $extra = []): array
    {
        return array_merge([
            'upstream_id' => null,
            'endpoint_code' => $endpointCode,
            'not_bound_tip' => self::DEFAULT_NOT_BOUND_TIP,
        ], $extra);
    }

    /**
     * 后台配置表单里公共的那几项
     */
    protected static function commonConfigSchema(string $endpointCode, string $endpointHint = ''): array
    {
        return [
            [
                'key' => 'upstream_id',
                'label' => '承接上游',
                'type' => 'select',
                'source' => 'third_api_configs',
                'required' => false,
                'hint' => '留空则依次用「功能绑定上的上游 → 机器人默认上游」；With Token 取自该上游配置的 token',
            ],
            [
                'key' => 'endpoint_code',
                'label' => '平台接口',
                'type' => 'select',
                'source' => 'platform_endpoints',
                'required' => true,
                'default' => $endpointCode,
                'hint' => $endpointHint !== ''
                    ? $endpointHint
                    : '接口路径与方法在「上游接口配置」里可为每个上游单独覆盖',
            ],
            [
                'key' => 'not_bound_tip',
                'label' => '未绑定商户号时的提示',
                'type' => 'text',
                'required' => false,
                'default' => self::DEFAULT_NOT_BOUND_TIP,
                'hint' => '可写 {{bind_usage}} 指定绑定命令出现的位置；不写则自动追加在末尾',
            ],
        ];
    }

    /**
     * 本群绑定的商户号
     *
     * 绑定关系的唯一数据源是 merchant_routes（/bd 写的就是它）；
     * 其次是 FeatureExecutor::withDefaultMerchant() 注入的 group_configs.mid。
     * 不能只读 $context->value('merchant_id')：feature_data 按功能分桶，
     * 本功能那一桶里通常什么都没有。
     */
    protected function resolveMerchantId(FeatureContext $context): string
    {
        $bound = app(MerchantRouteService::class)->forChat((string) $context->chatId);

        if ($bound !== []) {
            return trim((string) reset($bound));
        }

        return trim((string) ($context->value('merchant_id') ?? ''));
    }

    /**
     * 未绑定提示：绑定命令按后台实际配置动态拼，不写死 /bm
     */
    protected function notBoundTip(FeatureContext $context): string
    {
        $tip = (string) $context->config('not_bound_tip', self::DEFAULT_NOT_BOUND_TIP);

        if ($tip === self::LEGACY_NOT_BOUND_TIP || trim($tip) === '') {
            $tip = self::DEFAULT_NOT_BOUND_TIP;
        }

        $usage = $this->bindUsage();
        $tip = str_replace('{{bind_usage}}', $usage, $tip);

        if ($usage !== '' && ! str_contains($tip, $usage)) {
            $tip .= '，请先执行 ' . $usage;
        }

        return $tip;
    }

    /**
     * 「绑定商户号」功能当前实际使用的命令
     */
    protected function bindUsage(): string
    {
        $command = FeatureCommands::query()
            ->where('enabled', true)
            ->whereHas('feature', fn ($q) => $q->where('driver', BindMerchant::key()))
            ->orderBy('id')
            ->first();

        if (! $command) {
            return '';
        }

        $usage = trim((string) ($command->usage ?? ''));

        return $usage !== '' ? $usage : '/' . $command->command;
    }

    /**
     * 调上游接口
     *
     * 路径模板里的占位符、With Token、Bearer 头、JSON body 全部由
     * BaseCustomFeature::callEndpoint() 统一处理（上游配置在后台）。
     */
    protected function callUpstream(FeatureContext $context, string $endpointCode, array $params, string $method): array
    {
        $code = trim((string) $context->config('endpoint_code', ''));

        return $this->callEndpoint(
            $code !== '' ? $code : $endpointCode,
            $params,
            $context,
            $method
        );
    }

    /**
     * 把上游响应整理成模板变量
     *
     * 上游有的把业务字段直接放顶层，有的包一层 data，个别还包两层。
     * 这里三种写法都照顾到，模板里 {{amount}} / {{data.amount}} 都能取到。
     * $extra 是命令输入（如 warn_funds），优先级最高 —— 上游可能回 NULL，
     * 回复里要显示的是用户刚设置的值，不是那个 NULL。
     */
    protected function responseVars(array $response, array $extra = []): array
    {
        $vars = $response;

        $inner = data_get($response, 'data');
        if (is_array($inner)) {
            $vars = array_merge($vars, $inner);
            $vars['data'] = $inner;

            $deeper = data_get($inner, 'data');
            if (is_array($deeper)) {
                $vars = array_merge($vars, $deeper);
            }
        }

        return array_merge($vars, $extra, [
            'raw_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * 后台留空模板时用代码里的默认文案
     */
    protected function renderText(FeatureContext $context, string $key, string $default, array $vars): string
    {
        $template = (string) $context->config($key, '');

        return $this->render(trim($template) !== '' ? $template : $default, $vars, $context);
    }

    /**
     * 取第一个命令参数作为金额，保留原始字符串（避免 99999.00 被格式化）
     */
    protected function amountArg(FeatureContext $context): ?string
    {
        $raw = trim((string) ($context->arg(0) ?? ''));

        if ($raw === '' || ! is_numeric($raw) || (float) $raw < 0) {
            return null;
        }

        return $raw;
    }

    /**
     * 清掉「字段：-」这种没有值的行
     *
     * 代收/代付、不同状态返回的参数个数不一样，模板里全部字段都写上时，
     * 缺的会被 TemplateRenderer 渲染成 '-'。按行删掉比让群里看十几行 '-' 干净。
     */
    protected function stripEmptyLines(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $lines = array_values(array_filter(
            $lines,
            fn (string $line) => ! preg_match('/^\s*[^:：]*[:：]\s*-+\s*$/u', $line)
        ));

        return trim(implode("\n", $lines));
    }
}
