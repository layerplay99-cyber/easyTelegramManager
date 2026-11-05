<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Translate;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Services\Feature\Drivers\Custom\Merchant\BindMerchant;
use Modules\Telegram\Services\MerchantRouteService;

/**
 * 「按群语种生成单语回复」的公共能力
 *
 * 放在 Services/Translate/ 而不是 Drivers/Custom/Merchant/：
 * 两个功能注册器都会扫描 Drivers/Custom/ 并尝试 class_exists()，
 * trait 放那儿虽会被跳过，但混在功能目录里容易被误当成新功能。
 *
 * 语种只认后台「群设置 → 语种配置」（zh/en/vi/th，zh 对应语言包 zh_CN），
 * 文案在 lang/{locale}/merchant.php。返回统一带 skip_localize，
 * 让 FeatureExecutor::localize() 不要再追加一段译文（那是双语，且会二次翻译）。
 *
 * 现成的使用者：AbstractMerchantFeature（4 个新命令）、
 * BindMerchant / GetMerchantBalance / SetTradeOperator（3 个老命令）。
 */
trait LocalizedReply
{
    /**
     * 旧版本写死在 features.config 里的中文默认值。
     * 识别成「没自定义」，升级后才会走语言包；后台真填过的内容不动。
     */
    protected const LEGACY_TEXTS = [
        '本群还没有绑定商户号',
        '本群还没有绑定商户号，请先执行 /bm <商户号>',
        '该商户不存在',
        '✅ 本群已绑定商户号：{{merchant_id}}',
        '余额：{{data.balance}} 元',
        '✅ 已设置操作人：{{summary}}',
    ];

    /**
     * 群语种 → Laravel 语言包目录名
     *
     * 显式传 locale 给 __()，不改 app()->setLocale()：webhook / 队列里没有
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
     * 订单字段名 → 当前群语种的标签；字典里没有就用原始字段名
     */
    protected function fieldLabel(FeatureContext $context, string $field): string
    {
        $full = 'merchant.fields.' . $field;
        $label = __($full, [], $this->locale($context));

        return is_string($label) && $label !== $full ? $label : $field;
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
     * 后台自定义文案：旧默认值视为「没填」，真填过的原样返回（不翻译）
     */
    protected function customText(FeatureContext $context, string $key): string
    {
        $text = trim((string) $context->config($key, ''));

        return in_array($text, self::LEGACY_TEXTS, true) ? '' : $text;
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
     * 未绑定提示：后台填了固定文案就用它，留空按群语种翻译；
     * 绑定命令按后台实际配置动态拼，不写死 /bm
     */
    protected function notBoundTip(FeatureContext $context): string
    {
        $tip = $this->customText($context, 'not_bound_tip') ?: $this->t($context, 'common.not_bound');
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
}
