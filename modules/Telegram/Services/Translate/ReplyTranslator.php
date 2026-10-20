<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Translate;

use Aws\Translate\TranslateClient;
use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Services\Feature\TemplateRenderer;

/**
 * 群回复双语化
 *
 * 群标记了非默认语种时，在默认语种（中文）文案后面追加一段该语种的文字。
 * 调用点是 FeatureExecutor 发消息之前 —— 所有功能自动生效，不用改任何驱动。
 *
 * 第二段的来源两级：
 *   1) 功能配置里配了 reply_templates[<语种>] → 直接渲染它。
 *      业务术语（商户号、流水号、余额）机器翻译容易翻错，重要文案建议配这个。
 *   2) 没配 → 调 AWS Translate 翻译，结果按「原文 + 目标语种」缓存。
 *
 * 群回复就那几套固定文案，缓存命中率接近 100%，翻译费用可以忽略。
 */
class ReplyTranslator
{
    /** 单次翻译的字符上限（AWS TranslateText 限制 10KB，留足余量） */
    private const MAX_CHARS = 4000;

    public function __construct(protected TemplateRenderer $renderer)
    {
    }

    /**
     * 生成双语文本
     *
     * @param string               $text     已渲染好的默认语种文案
     * @param string               $language 群语种
     * @param array<string, mixed> $config   功能配置（可含 reply_templates）
     * @param array<string, mixed> $data     渲染模板用的数据
     */
    public function bilingual(string $text, string $language, array $config = [], array $data = []): string
    {
        $text = trim($text);

        if ($text === '' || ! $this->enabled()) {
            return $text;
        }

        $source = strtolower(trim((string) config('telegram.translate.source', 'zh')));
        $language = strtolower(trim($language));

        // 没标语种 / 标的就是默认语种 → 不用翻
        if ($language === '' || $language === $source) {
            return $text;
        }

        $second = $this->fromTemplate($config, $language, $data);

        if ($second === '') {
            $second = $this->fromAws($text, $source, $language);
        }

        if ($second === '') {
            return $text;
        }

        return $text . (string) config('telegram.translate.separator', "\n") . $second;
    }

    public function enabled(): bool
    {
        return (bool) config('telegram.translate.enabled', true);
    }

    /**
     * 功能配置里有没有这个语种的专属文案
     */
    protected function fromTemplate(array $config, string $language, array $data): string
    {
        $templates = $config['reply_templates'] ?? null;

        if (! is_array($templates) || ! isset($templates[$language])) {
            return '';
        }

        $template = trim((string) $templates[$language]);

        if ($template === '') {
            return '';
        }

        return trim($this->renderer->render($template, $data));
    }

    /**
     * 调 AWS Translate（带缓存）
     */
    protected function fromAws(string $text, string $source, string $target): string
    {
        if (mb_strlen($text) > self::MAX_CHARS) {
            return '';
        }

        foreach (['key' => 'AWS_ACCESS_KEY_ID', 'secret' => 'AWS_SECRET_ACCESS_KEY'] as $field => $env) {
            if (trim((string) config('telegram.aws.' . $field, '')) === '') {
                return '';
            }
        }

        $cacheKey = 'telegram:translate:' . sha1($source . '>' . $target . ':' . $text);

        try {
            return (string) Cache::remember(
                $cacheKey,
                now()->addMinutes((int) config('telegram.translate.cache_ttl', 43200)),
                fn () => $this->translate($text, $source, $target)
            );
        } catch (\Throwable) {
            // 翻译挂了不能影响主回复
            return '';
        }
    }

    protected function translate(string $text, string $source, string $target): string
    {
        $result = $this->client()->translateText([
            'SourceLanguageCode' => $source,
            'TargetLanguageCode' => $target,
            'Text'               => $text,
        ]);

        return trim((string) (is_array($result) ? ($result['TranslatedText'] ?? '') : ($result['TranslatedText'] ?? '')));
    }

    protected function client(): TranslateClient
    {
        return new TranslateClient([
            'version'     => 'latest',
            'region'      => (string) config('telegram.aws.region', 'us-east-1'),
            'credentials' => [
                'key'    => (string) config('telegram.aws.key'),
                'secret' => (string) config('telegram.aws.secret'),
            ],
            'http' => [
                'connect_timeout' => 5,
                'timeout'         => (int) config('telegram.translate.timeout', 10),
            ],
            'retries' => [
                'mode'         => 'standard',
                'max_attempts' => 2,
            ],
        ]);
    }
}
