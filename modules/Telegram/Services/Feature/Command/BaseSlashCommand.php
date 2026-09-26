<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Command;

use Modules\Telegram\Contracts\SlashCommand;

/**
 * 斜杠命令基类
 *
 * 提供 params() / configSchema() / usage() 的默认实现，
 * 子类一般只需要实现 name() / description() / handle()。
 */
abstract class BaseSlashCommand implements SlashCommand
{
    /**
     * 用法示例，默认按参数定义自动生成（如 "/cx <order_id>"）
     */
    public function usage(): string
    {
        $parts = ['/' . $this->name()];

        foreach ($this->params() as $param) {
            $name = $param['name'] ?? '';
            $required = (bool) ($param['required'] ?? false);

            $parts[] = $required ? "<{$name}>" : "[{$name}]";
        }

        return implode(' ', $parts);
    }

    /**
     * 参数定义，默认无参数
     */
    public function params(): array
    {
        return [];
    }

    /**
     * 后台配置项定义，默认无
     */
    public function configSchema(): array
    {
        return [];
    }

    /**
     * 多语言格式化：主语言 + 附加语言逐行拼接
     */
    protected function formatMultilang(array $data, array $lang = [], string $langFile = 'fields', string $primary = 'zh_CN'): string
    {
        return collect($data)->map(function ($value, $key) use ($primary, $lang, $langFile) {
            $text = __("{$langFile}.{$key}", [$key => $value], $primary);

            foreach ($lang as $language) {
                $text .= "\n" . __("{$langFile}.{$key}", [$key => $value], $language);
            }

            return $text;
        })->join("\n");
    }

    /**
     * 统一失败文案
     */
    protected function failed(): string
    {
        return 'Something went wrong.';
    }

    /**
     * 从缓存取上游第三方 API 配置
     *
     * @return mixed 配置模型或 null
     */
    protected function thirdApiConfig(CommandContext $context): mixed
    {
        $key = $context->config('thridconfig.0') ?? $context->config('thridconfig');

        if (is_array($key)) {
            $key = $key[0] ?? null;
        }

        if ($key === null || $key === '') {
            return null;
        }

        return \Illuminate\Support\Facades\Cache::get('third_api_configs', [])[$key] ?? null;
    }

    /**
     * 从缓存取群配置
     */
    protected function groupConfig(CommandContext $context): ?array
    {
        return \Illuminate\Support\Facades\Cache::get('group_configs', [])[$context->chatId] ?? null;
    }
}
