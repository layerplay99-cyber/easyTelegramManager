<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Plugin;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Modules\Telegram\Models\PluginSetting;

/**
 * 插件代码 AI 审核（可选，后台开关控制）
 *
 * 走 OpenAI 兼容的 /chat/completions：base_url / model / api_key 都在后台配，
 * 换成自建或其它厂商的兼容网关不用改代码。
 *
 * 定位是「脚本审核的补充」：脚本规则抓确定的危险模式，AI 抓逻辑陷阱
 * （越权读写数据、泄露密钥、无限循环、明显的后门逻辑）。
 * 因此它拿到的输入里同时包含脚本审核的结论，避免重复报同一件事。
 *
 * 失败即拒绝（fail-closed）：开关打开却调不通模型时，宁可拦住安装，
 * 也不能让「审核看着开了、其实一直跳过」。
 */
class PluginAiReviewer
{
    /**
     * 单文件送给模型的字符上限 / 送审总字符上限
     */
    protected const PER_FILE_CHARS = 6000;
    protected const TOTAL_CHARS = 40000;

    public function enabled(): bool
    {
        $setting = PluginSetting::current();

        return (int) $setting->ai_enabled === 1 && trim((string) $setting->ai_api_key) !== '';
    }

    /**
     * 审核插件目录
     *
     * @param array{errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>, stats: array<string, int>} $audit
     * @return array{verdict: string, score: int, reasons: array<int, string>, model: string, error: string|null}
     */
    public function review(string $dir, array $audit): array
    {
        $setting = PluginSetting::current();

        try {
            $response = Http::withToken((string) $setting->ai_api_key)
                ->timeout(max(10, (int) $setting->ai_timeout))
                ->acceptJson()
                ->post(rtrim((string) $setting->ai_base_url, '/') . '/chat/completions', [
                    'model' => (string) $setting->ai_model,
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $this->userPrompt($dir, $audit)],
                    ],
                ]);

            if (! $response->successful()) {
                return $this->fail($setting, 'AI 接口返回 HTTP ' . $response->status());
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $parsed = $this->parse($content);

            if ($parsed === null) {
                return $this->fail($setting, 'AI 返回内容无法解析为 JSON');
            }

            return [
                'verdict' => $parsed['verdict'],
                'score' => $parsed['score'],
                'reasons' => $parsed['reasons'],
                'model' => (string) $setting->ai_model,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return $this->fail($setting, $e->getMessage());
        }
    }

    /**
     * @return array{verdict: string, score: int, reasons: array<int, string>, model: string, error: string}|array{verdict: string, score: int, reasons: array<int, string>, model: string, error: null}
     */
    protected function fail(PluginSetting $setting, string $message): array
    {
        return [
            'verdict' => 'reject',
            'score' => 0,
            'reasons' => ['AI 审核不可用：' . $message],
            'model' => (string) $setting->ai_model,
            'error' => $message,
        ];
    }

    /**
     * @return array{verdict: string, score: int, reasons: array<int, string>}|null
     */
    protected function parse(string $content): ?array
    {
        $content = trim($content);

        // 有些网关还是会给 ```json 包裹
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $content, $m)) {
            $content = $m[1];
        }

        $data = json_decode($content, true);

        if (! is_array($data)) {
            return null;
        }

        $verdict = strtolower((string) ($data['verdict'] ?? ''));

        if (! in_array($verdict, ['approve', 'reject'], true)) {
            return null;
        }

        $reasons = $data['reasons'] ?? [];

        return [
            'verdict' => $verdict,
            'score' => (int) ($data['score'] ?? 0),
            'reasons' => is_array($reasons)
                ? array_values(array_map(fn ($r) => (string) $r, $reasons))
                : [(string) $reasons],
        ];
    }

    protected function systemPrompt(): string
    {
        return <<<'TXT'
你是 Telegram 机器人平台的功能插件安全审核员。插件是第三方上传的 PHP 代码，运行在平台进程内。

审核重点（按严重程度）：
1. 是否窃取或外发密钥（机器人 token、上游 access_key/secret_key、数据库口令）；
2. 是否越权访问其它商户/用户的数据，或绕过平台的功能绑定与权限；
3. 是否执行系统命令、写核心目录、访问内网地址（这类脚本审核已拦，重复出现说明有意规避）；
4. 是否存在明显的后门、隐藏触发条件、与声明功能无关的行为；
5. 是否存在会导致平台不可用的行为（死循环、超大内存、无超时外呼）。

只输出 JSON，不要任何解释文字，格式：
{"verdict":"approve" 或 "reject","score":0-100 的整数（越高越安全）,"reasons":["中文理由", ...]}
没有问题时 reasons 返回空数组。
TXT;
    }

    protected function userPrompt(string $dir, array $audit): string
    {
        $parts = [];
        $parts[] = '## 脚本审核结论（已自动检测）';
        $parts[] = 'errors: ' . (empty($audit['errors']) ? '无' : json_encode($audit['errors'], JSON_UNESCAPED_UNICODE));
        $parts[] = 'warnings: ' . (empty($audit['warnings']) ? '无' : json_encode($audit['warnings'], JSON_UNESCAPED_UNICODE));

        $parts[] = '';
        $parts[] = '## 插件代码';

        $budget = self::TOTAL_CHARS;

        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() !== 'php' || $budget <= 0) {
                continue;
            }

            $code = (string) @file_get_contents($file->getPathname());
            $code = mb_substr($code, 0, min(self::PER_FILE_CHARS, $budget));
            $budget -= mb_strlen($code);

            $parts[] = '### ' . $file->getRelativePathname();
            $parts[] = "```php\n" . $code . "\n```";
        }

        return implode("\n", $parts);
    }
}
