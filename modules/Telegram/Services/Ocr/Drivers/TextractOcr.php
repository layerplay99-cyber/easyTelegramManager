<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Ocr\Drivers;

use Aws\Textract\TextractClient;
use Modules\Telegram\Contracts\OcrDriver;

/**
 * AWS Textract 识图
 *
 * 用同步的 DetectDocumentText：传图片二进制进去，直接得到 Blocks，
 * 取 BlockType=LINE 的就是按行排好的文本。
 *
 * 为什么不用 StartDocumentTextDetection：那是给 PDF / 多页文档的异步接口，
 * 要轮询 SNS 或 SQS，群里一张截图没必要。
 *
 * 凭证来自 config('telegram.ocr.aws')，即 .env 的
 * AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_REGION —— 是通用的一套，
 * 不走后台「上游配置」（Textract 是 SDK + SigV4 签名，不是「URL + token」那套模型）。
 */
class TextractOcr implements OcrDriver
{
    public static function key(): string
    {
        return 'textract';
    }

    public function recognize(string $binary, string $language = ''): array
    {
        $this->assertConfigured();

        $result = $this->client()->detectDocumentText([
            'Document' => ['Bytes' => $binary],
        ]);

        $blocks = $result['Blocks'] ?? [];

        $lines = [];
        $confidenceSum = 0.0;
        $confidenceCount = 0;

        foreach ((array) $blocks as $block) {
            if (($block['BlockType'] ?? '') !== 'LINE') {
                continue;
            }

            $text = trim((string) ($block['Text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $lines[] = $text;

            if (isset($block['Confidence'])) {
                $confidenceSum += (float) $block['Confidence'];
                $confidenceCount++;
            }
        }

        return [
            'text'       => implode('', $lines),
            'lines'      => $lines,
            'confidence' => $confidenceCount > 0 ? round($confidenceSum / $confidenceCount, 2) : null,
            'driver'     => static::key(),
            'raw'        => $blocks,
        ];
    }

    protected function assertConfigured(): void
    {
        foreach (['key' => 'AWS_ACCESS_KEY_ID', 'secret' => 'AWS_SECRET_ACCESS_KEY'] as $field => $env) {
            if (trim((string) config('telegram.aws.' . $field, '')) === '') {
                throw new \RuntimeException("未配置 {$env}，无法调用 AWS Textract");
            }
        }
    }

    protected function client(): TextractClient
    {
        return new TextractClient([
            'version'     => 'latest',
            'region'      => (string) config('telegram.aws.region', 'us-east-1'),
            'credentials' => [
                'key'    => (string) config('telegram.aws.key'),
                'secret' => (string) config('telegram.aws.secret'),
            ],
            // OCR 是重活：连不上要快失败，总耗时也不能拖垮队列 worker
            'http'    => [
                'connect_timeout' => 5,
                'timeout'         => (int) config('telegram.ocr.aws.timeout', 15),
            ],
            // 限流（ThrottlingException）自动退避重试，超了再抛给上层
            'retries' => [
                'mode'         => 'standard',
                'max_attempts' => 3,
            ],
        ]);
    }
}
