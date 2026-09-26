<?php
declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Command;

class BaseCommandHandler
{
    private const DEFAULT_LANG_FILE = 'fields';
    private const DEFAULT_PRIMARY_LANG = 'zh_CN';
    private const ERROR_MESSAGE = 'Something went wrong.';

    /**
     * 格式化成功参数
     *
     * @param array $data 数据数组
     * @param array $lang 语言数组
     * @param string $langFile 语言文件
     * @param string $primary 主语言
     * @return string
     */
    protected function successfulParams(
        array $data,
        array $lang = [],
        string $langFile = self::DEFAULT_LANG_FILE,
        string $primary = self::DEFAULT_PRIMARY_LANG
    ): string {
        return collect($data)->map(function ($value, $key) use ($primary, $lang, $langFile) {
            $translatedTemplate = __("{$langFile}.{$key}", [$key => $value], $primary);

            if (count($lang)) {
                foreach ($lang as $language) {
                    $translated = __("{$langFile}.{$key}", [$key => $value], $language);
                    $translatedTemplate .= "\n" . $translated;
                }
            }

            return $translatedTemplate;
        })->join("\n");
    }

    /**
     * 返回失败消息
     *
     * @return string
     */
    protected function failed(): string
    {
        return self::ERROR_MESSAGE;
    }
}
