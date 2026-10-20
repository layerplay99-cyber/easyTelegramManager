<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Ocr;

use Modules\Telegram\Contracts\OcrDriver;

/**
 * OCR 引擎解析
 *
 * 选引擎的规则全在配置里，不写 if-else：
 *   群语种 → config('telegram.ocr.by_language') → 没配就回落 default
 *
 * 加引擎、改某语种的引擎，都只动 config/telegram.php。
 */
class OcrManager
{
    /**
     * 按语种取引擎
     */
    public function driverFor(?string $language): OcrDriver
    {
        $language = strtolower(trim((string) $language));

        $key = $language !== ''
            ? (string) (config('telegram.ocr.by_language.' . $language) ?: '')
            : '';

        if ($key === '') {
            $key = (string) config('telegram.ocr.default', 'textract');
        }

        $class = config('telegram.ocr.drivers.' . $key);

        if (! is_string($class) || ! class_exists($class)) {
            throw new \RuntimeException("未登记的 OCR 引擎：{$key}");
        }

        $driver = app($class);

        if (! $driver instanceof OcrDriver) {
            throw new \RuntimeException("OCR 引擎 {$key}（{$class}）未实现 OcrDriver");
        }

        return $driver;
    }

    /**
     * 群可标记的语种（后台下拉读这里）
     *
     * @return array<string, string>
     */
    public function languages(): array
    {
        return (array) config('telegram.ocr.languages', []);
    }

    /**
     * 默认语种
     */
    public function defaultLanguage(): string
    {
        return (string) config('telegram.ocr.default_language', 'zh');
    }

    /**
     * 后台下拉选项：群语种
     *
     * @return array<int, array<string, string>>
     */
    public static function languageOptions(): array
    {
        $options = [];

        foreach ((array) config('telegram.ocr.languages', []) as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }
}
