<?php

declare(strict_types=1);

namespace Modules\Telegram\Contracts;

/**
 * OCR 引擎
 *
 * 识图功能不直接依赖某一家 OCR：新增引擎 = 实现本接口 + 在
 * config/telegram.php 的 ocr.drivers 里登记一行，业务代码不用动。
 * 这样以后「泰文换 Google Vision」「某语种换国内厂商」都只是改配置。
 */
interface OcrDriver
{
    /**
     * 引擎标识，对应 config('telegram.ocr.drivers') 的键
     */
    public static function key(): string;

    /**
     * 识别一张图片
     *
     * @param string $binary   图片二进制
     * @param string $language 群标记的语种（zh/en/vi/th…），引擎可据此调整参数
     *
     * @return array{
     *     text: string,          // 所有行拼成的字符串（默认无分隔符）
     *     lines: array<int,string>,
     *     confidence: float|null,
     *     driver: string,
     *     raw: mixed
     * }
     *
     * @throws \Throwable 识别失败（凭证缺失、超时、限流…）由调用方转成用户提示
     */
    public function recognize(string $binary, string $language = ''): array;
}
