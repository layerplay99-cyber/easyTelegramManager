<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Ocr;

use Modules\Telegram\Contracts\EndpointProvider;
use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\Ocr\OcrManager;
use Modules\Telegram\Services\TelegramMediaService;

/**
 * 识图：群友发图片 → 取回图片 → OCR → 把识别出的文本拼成一个字符串
 *
 * 触发方式是 message（不是 command），由后台「功能绑定」勾到群上生效：
 * 群里一发图片就走这里。具体放行哪些媒体类型看 config.message_types。
 *
 * OCR 引擎不在这里写死，走 OcrManager：
 *   群语种 → config('telegram.ocr.by_language') → 没配就回落 default
 * 以后「泰文换一个引擎」= 加个驱动类 + 改一行配置，本文件不用动。
 *
 * 识别结果两种用法：
 *   1) 配了「提交接口」→ 把文本 POST 给上游，用上游返回值渲染回复
 *   2) 没配 → 直接把文本回复到群里
 */
class ImageOcr extends BaseCustomFeature implements EndpointProvider
{
    public static function key(): string
    {
        return 'custom.ocr_image';
    }

    public static function label(): string
    {
        return '识图（OCR 提取文字）';
    }

    public static function featureName(): string
    {
        return '识图';
    }

    public static function featureDescription(): string
    {
        return '群里发图片时识别图中文字，拼成一个字符串后回复到群里或提交给上游';
    }

    public static function uuid(): string
    {
        return 'ocrImage';
    }

    public static function group(): string
    {
        return '自定义';
    }

    /**
     * 图片触发，不是斜杠命令
     */
    public static function triggerName(): string
    {
        return 'message';
    }

    public static function triggers(): array
    {
        return ['message', 'manual'];
    }

    /**
     * 本功能要用到的上游接口
     */
    public static function endpoints(): array
    {
        return [
            'ocr.text.submit' => [
                'name'           => '提交识图结果',
                'method'         => 'POST',
                'path_template'  => 'api/ocr/text',
                'params_schema'  => [
                    ['name' => 'ocr_text', 'required' => true, 'desc' => '识别出的完整文本', 'map_to' => 'ocrText'],
                    ['name' => 'language', 'required' => false, 'desc' => '群语种', 'map_to' => 'language'],
                ],
                'response_schema' => [],
                'remark'         => '把 OCR 结果交给上游处理；不配也能用，识别结果会直接回复到群里',
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return [
            'message_types'  => ['photo'],
            'upstream_id'    => null,
            'endpoint_code'  => '',
            // 行与行之间怎么拼：默认直接连起来（上游要的就是一整串）
            'line_separator' => '',
            'reply_template' => '{ocr_text}',
            'no_text_tip'    => '这张图里没有识别到文字',
        ];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key'      => 'message_types',
                'label'    => '处理的消息类型',
                'type'     => 'select',
                'source'   => 'update_media_types',
                // 多选：数组形式，运行时按 UpdateIntent 的媒体类型过滤
                'multiple' => true,
                'required' => false,
                'default'  => ['photo'],
                'hint'     => '默认只处理图片；留空表示不限制类型',
            ],
            [
                'key'      => 'upstream_id',
                'label'    => '承接上游',
                'type'     => 'select',
                'source'   => 'third_api_configs',
                'required' => false,
                'hint'     => '留空则依次用「功能绑定上的上游 → 机器人默认上游」',
            ],
            [
                'key'      => 'endpoint_code',
                'label'    => '提交接口',
                'type'     => 'select',
                'source'   => 'platform_endpoints',
                'required' => false,
                'hint'     => '选了就把识别结果 POST 给上游；不选则直接把文本回复到群里',
            ],
            [
                'key'      => 'line_separator',
                'label'    => '行分隔符',
                'type'     => 'text',
                'required' => false,
                'default'  => '',
                'hint'     => '每行之间插什么。留空=直接连成一整串（AWS 识别结果本身就是紧凑的）；要换行就填 \\n',
            ],
            [
                'key'      => 'reply_template',
                'label'    => '回复模板',
                'type'     => 'text',
                'required' => false,
                'default'  => '{ocr_text}',
                'hint'     => '可用 {ocr_text}（完整文本）、{lines}、{language}、{confidence}；配了提交接口时还能用上游返回的字段',
            ],
            [
                'key'      => 'no_text_tip',
                'label'    => '没识别到文字时的提示',
                'type'     => 'text',
                'required' => false,
                'default'  => '这张图里没有识别到文字',
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $allowed = array_values(array_filter((array) ($context->config('message_types', ['photo']) ?: [])));
        $mediaType = (string) $context->mediaType();

        if ($allowed !== [] && ! in_array($mediaType, $allowed, true)) {
            return $this->fail('识图功能只处理：' . implode(' / ', $allowed));
        }

        if (! $context->bot) {
            return $this->fail('缺少机器人信息，无法下载图片');
        }

        $fileId = (string) ($context->payload('file_id') ?? '');

        if ($fileId === '') {
            return $this->fail('没有拿到图片（file_id 为空）');
        }

        try {
            $binary = app(TelegramMediaService::class)->downloadFile($context->bot, $fileId);
        } catch (\Throwable $e) {
            return $this->fail('下载图片失败：' . $e->getMessage());
        }

        $maxBytes = (int) config('telegram.ocr.max_bytes', 9 * 1024 * 1024);

        if (strlen($binary) > $maxBytes) {
            return $this->fail(sprintf(
                '图片太大（%.1f MB），OCR 接口上限 %d MB',
                strlen($binary) / 1024 / 1024,
                (int) ($maxBytes / 1024 / 1024)
            ));
        }

        // 群语种决定用哪套 OCR 逻辑（目前都走默认引擎，映射表里可以按语种换）
        $language = BotGroups::languageOf($context->chatId);

        try {
            $ocr = app(OcrManager::class)->driverFor($language)->recognize($binary, $language);
        } catch (\Throwable $e) {
            return $this->fail('识图失败：' . $e->getMessage());
        }

        $lines = array_values(array_filter((array) ($ocr['lines'] ?? [])));
        $text = implode((string) $context->config('line_separator', ''), $lines);

        if ($text === '') {
            return $this->fail((string) $context->config('no_text_tip', '这张图里没有识别到文字'));
        }

        // 存一份，其它功能（比如按语种翻译、二次提交）可以直接复用
        $this->putData($context, [
            'ocr_text'     => $text,
            'ocr_language' => $language,
        ]);

        $viewData = [
            'ocr_text'   => $text,
            'lines'      => $lines,
            'language'   => $language,
            'confidence' => $ocr['confidence'] ?? null,
            'driver'     => $ocr['driver'] ?? '',
        ];

        // 配了提交接口就先交给上游，用上游返回值渲染
        $endpointCode = (string) $context->config('endpoint_code', '');

        if ($endpointCode !== '') {
            $data = $this->callEndpoint(
                $endpointCode,
                ['ocr_text' => $text, 'language' => $language],
                $context,
                'POST'
            );

            if (isset($data['_error'])) {
                return $this->fail('提交识图结果失败：' . $data['_error']);
            }

            $viewData = array_merge($viewData, $data);
        }

        return $this->reply(
            $this->render((string) $context->config('reply_template', '{ocr_text}'), $viewData, $context),
            $viewData
        );
    }
}
