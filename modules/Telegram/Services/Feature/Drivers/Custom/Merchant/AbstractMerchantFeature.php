<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\MerchantRouteService;

/**
 * 商户类功能公共基类
 *
 * 这一组功能都是「群里发命令 → 拿本群绑定的商户号 → 调上游 → 按群语种回复」。
 * 公共部分收在这里：上游选择、商户号解析、语言包取词、单语结果封装。
 *
 * ⚠️ 语种只认后台「群设置 → 语种配置」（BotGroups::languageOf()），
 *    值 zh / en / vi / th；语言包目录里中文是 zh_CN，需要映射。
 *    文案在 lang/{locale}/merchant.php，不在代码里写死。
 *
 * ⚠️ 统一管线 FeatureExecutor::localize() 会给回复追加一段译文（双语）。
 *    这几个功能自己已经按群语种生成了完整单语文案，所以返回值统一带
 *    skip_localize，避免「群语种 + 又被翻译一遍」。
 *
 * ⚠️ 本类刻意不 implements EndpointProvider：PlatformEndpointRegistry::providers()
 *    会扫描 Services/ 下所有实现了该接口的类（包括抽象类），接口定义放在各自
 *    用到它的功能类里更清楚，删功能时定义也随之消失。
 *    抽象类也会被两个注册器的 isAbstract() 跳过，不会多出一个空功能。
 */
abstract class AbstractMerchantFeature extends BaseCustomFeature
{
    /** 旧版写死在 features.config 里的中文默认提示，遇到就当作「没自定义」 */
    private const LEGACY_NOT_BOUND_TIPS = [
        '本群还没有绑定商户号',
        '本群还没有绑定商户号，请先执行 /bm <商户号>',
    ];

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

    protected static function commonDefaultConfig(string $endpointCode, array $extra = []): array
    {
        return array_merge([
            'upstream_id' => null,
            'endpoint_code' => $endpointCode,
            'not_bound_tip' => '',
        ], $extra);
    }

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
                'default' => '',
                'hint' => '留空则按群语种自动翻译；填写则固定使用该文案（不会翻译）。可写 {{bind_usage}} 指定绑定命令位置',
            ],
        ];
    }

    /**
     * 群语种 → Laravel 语言包目录名
     *
     * 用显式 locale 调 __()，不碰 app()->setLocale()：webhook / 队列里没有
     * Accept-Language 头，改全局 locale 会污染同一进程里的其它任务。
     */
    protected function locale(FeatureContext $context): string
    {
        $lang = strtolower(trim(BotGroups::languageOf($context->chatId)));
        $allowed = array_map('strval', array_keys((array) config('telegram.ocr.languages', ['zh' => ''])));

        if (! in_array($lang, $allowed, true)) {
            $lang = 'zh';
        }

        return $lang === 'zh' ? 'zh_CN' : $lang;
    }

    /**
     * 取一条当前群语种的文案（支持 :name 占位）
     */
    protected function t(FeatureContext $context, string $key, array $replace = []): string
    {
        $full = 'merchant.' . $key;
        $locale = $this->locale($context);
        $line = __($full, $replace, $locale);

        if (is_array($line)) {
            return '';
        }

        $line = (string) $line;

        // 缺译时回退中文，再缺就把 key 原样吐出来便于发现
        if ($line === $full && $locale !== 'zh_CN') {
            $line = (string) __($full, $replace, 'zh_CN');
        }

        return $line === $full ? $key : $line;
    }

    /**
     * 订单字段名 → 当前群语种的标签
     *
     * 上游返回了字典里没有的字段时，直接用原始字段名当标签 ——
     * 「上游返回什么就显示什么」，不因为没翻译就丢字段。
     */
    protected function fieldLabel(FeatureContext $context, string $field): string
    {
        $full = 'merchant.fields.' . $field;
        $label = __($full, [], $this->locale($context));

        if (! is_string($label) || $label === $full) {
            return $field;
        }

        return $label;
    }

    /**
     * 把 type / status 的英文枚举值翻成当前群语种
     */
    protected function valueLabel(FeatureContext $context, string $group, string $value): string
    {
        $map = __('merchant.' . $group, [], $this->locale($context));

        if (! is_array($map)) {
            return $value;
        }

        foreach ($map as $key => $label) {
            if (strcasecmp((string) $key, $value) === 0) {
                return (string) $label;
            }
        }

        return $value;
    }

    protected function fieldSeparator(FeatureContext $context): string
    {
        $separator = $this->t($context, 'field_separator');

        return $separator === 'field_separator' ? ': ' : $separator;
    }

    /**
     * 单语成功回复 / 失败回复：跳过统一管线的双语追加
     */
    protected function localizedReply(string $text, array $data = []): FeatureResult
    {
        return FeatureResult::reply($text, $data, ['skip_localize' => true]);
    }

    protected function localizedFail(string $text): FeatureResult
    {
        return FeatureResult::fail($text, ['skip_localize' => true]);
    }

    /**
     * 本群绑定的商户号
     *
     * 绑定关系的唯一数据源是 merchant_routes（/bd 写的就是它）；
     * 其次是 FeatureExecutor::withDefaultMerchant() 注入的 group_configs.mid。
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
     * 未绑定提示：后台填了固定文案就用它，留空按群语种翻译
     * 绑定命令按后台实际配置动态拼，不写死 /bm
     */
    protected function notBoundTip(FeatureContext $context): string
    {
        $custom = trim((string) $context->config('not_bound_tip', ''));

        if (in_array($custom, self::LEGACY_NOT_BOUND_TIPS, true)) {
            $custom = '';
        }

        $tip = $custom !== '' ? $custom : $this->t($context, 'common.not_bound');
        $usage = $this->bindUsage();

        if ($usage === '') {
            return $tip;
        }

        $tip = str_replace('{{bind_usage}}', $usage, $tip);

        if (! str_contains($tip, $usage)) {
            $tip .= $this->t($context, 'common.not_bound_suffix', ['usage' => $usage]);
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
     * 调上游接口：路径占位、With Token、Bearer 头、JSON body 全由 callEndpoint 处理
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
}
