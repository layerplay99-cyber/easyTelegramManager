<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Plugin;

use Illuminate\Support\Facades\File;

/**
 * 插件脚本审核（纯静态，不执行插件代码）
 *
 * 目标不是「审出所有坏代码」，而是把插件的破坏面关在插件目录里：
 *   - 不允许执行系统命令 / eval / 变量函数，杜绝「上传即拿服务器」；
 *   - 不允许访问内网与本机地址，杜绝对内网的 SSRF；
 *   - 不允许读写核心目录与密钥文件，插件只能碰自己的目录和数据库模型；
 *   - 命名空间必须限定在插件自己的前缀下，不能冒充核心类。
 *
 * 结果分两级：errors 阻断安装；warnings 只提示（插件里合理的文件写、超全局变量读取等）。
 */
class PluginAuditor
{
    /**
     * 单文件大小上限（字节）
     */
    public const MAX_FILE_SIZE = 512 * 1024;

    /**
     * 审核一个已解压的插件目录
     *
     * @return array{errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>, stats: array<string, int>}
     */
    public function scan(string $dir, string $namespacePrefix): array
    {
        $errors = [];
        $warnings = [];
        $phpFiles = 0;
        $bytes = 0;

        foreach (File::allFiles($dir) as $file) {
            $relative = $file->getRelativePathname();
            $size = $file->getSize();
            $bytes += $size;

            if ($size > self::MAX_FILE_SIZE) {
                $errors[] = $this->issue($relative, 0, 'size', 'error', '单文件超过 512KB，请拆分配置或资源');
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $phpFiles++;
            $lines = @file($file->getPathname()) ?: [];
            $content = implode('', $lines);

            foreach ($this->rules() as $rule) {
                foreach ($lines as $i => $line) {
                    if (! preg_match($rule['re'], $line)) {
                        continue;
                    }

                    $item = $this->issue($relative, $i + 1, $rule['id'], $rule['level'], $rule['message']);

                    if ($rule['level'] === 'error') {
                        $errors[] = $item;
                    } else {
                        $warnings[] = $item;
                    }
                }
            }

            // 命名空间必须限定在插件自己的前缀下：禁止冒充核心类
            if (! preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $content, $m)) {
                $errors[] = $this->issue($relative, 0, 'namespace', 'error', '缺少 namespace 声明');
            } elseif (! str_starts_with($m[1], $namespacePrefix)) {
                $errors[] = $this->issue(
                    $relative,
                    0,
                    'namespace',
                    'error',
                    "命名空间必须是 {$namespacePrefix}… ，当前为 {$m[1]}"
                );
            }

            // 语法错误：交给 token_get_all 的解析器（TOKEN_PARSE 会抛 ParseError）
            try {
                token_get_all($content, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $errors[] = $this->issue($relative, $e->getLine(), 'syntax', 'error', 'PHP 语法错误：' . $e->getMessage());
            }

            if (! str_contains($content, 'declare(strict_types=1)')) {
                $warnings[] = $this->issue($relative, 0, 'strict_types', 'warning', '建议声明 declare(strict_types=1)');
            }

            if (preg_match('/\/[A-Za-z0-9+\/]{300,}={0,2}/', $content)) {
                $warnings[] = $this->issue($relative, 0, 'obfuscation', 'warning', '发现超长 base64 片段，确认不是混淆代码');
            }
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'stats' => [
                'php_files' => $phpFiles,
                'bytes' => $bytes,
            ],
        ];
    }

    /**
     * 静态规则表
     *
     * 只做正则，是因为插件代码不需要「理解语义」——这几个函数/路径出现即高危，
     * 误伤成本低（提示用户改写法），漏放成本高（服务器被接管）。
     *
     * @return array<int, array{id: string, re: string, level: string, message: string}>
     */
    protected function rules(): array
    {
        return [
            // ---- 代码执行 / 命令执行 ----
            ['id' => 'eval', 're' => '/\beval\s*\(/', 'level' => 'error', 'message' => '禁止 eval()'],
            ['id' => 'shell', 're' => '/\b(exec|shell_exec|system|passthru|popen|proc_open|pcntl_exec|dl)\s*\(/', 'level' => 'error', 'message' => '禁止执行系统命令'],
            ['id' => 'create_function', 're' => '/\bcreate_function\s*\(/', 'level' => 'error', 'message' => '禁止 create_function()'],
            ['id' => 'variable_variable', 're' => '/\$\$\w+/', 'level' => 'error', 'message' => '禁止变量函数/变量变量'],

            // ---- 文件 / 目录 ----
            ['id' => 'dotenv', 're' => '/\.env\b/', 'level' => 'error', 'message' => '禁止访问 .env'],
            ['id' => 'etc_passwd', 're' => '#/etc/(passwd|shadow|sudoers)#', 'level' => 'error', 'message' => '禁止访问系统账号文件'],
            ['id' => 'core_path', 're' => '#(bootstrap/app\.php|config/database\.php|composer\.(json|lock)|vendor/)#', 'level' => 'error', 'message' => '禁止访问框架核心文件'],
            ['id' => 'write', 're' => '/\b(file_put_contents|unlink|rmdir|rename|copy|mkdir|chmod|chown)\s*\(/', 'level' => 'warning', 'message' => '文件写操作：确认只写插件自己或 storage 目录'],
            ['id' => 'traversal', 're' => '/\.\.\//', 'level' => 'warning', 'message' => '路径含 ../，确认没有越出插件目录'],

            // ---- 内网 / SSRF ----
            ['id' => 'loopback', 're' => '/\b(127\.0\.0\.1|localhost|0\.0\.0\.0|169\.254\.)/i', 'level' => 'error', 'message' => '禁止访问本机/链路本地地址'],
            ['id' => 'private_ip', 're' => '/\b(10\.\d{1,3}\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})\b/', 'level' => 'error', 'message' => '禁止访问内网地址'],

            // ---- 数据库 / 框架 ----
            ['id' => 'raw_sql', 're' => '/\bDB::(statement|unprepared)\s*\(/', 'level' => 'error', 'message' => '禁止执行裸 SQL，请使用模型/查询构造器'],
            ['id' => 'schema', 're' => '/\bSchema::(create|drop|table|rename)\s*\(/', 'level' => 'error', 'message' => '禁止在插件里改表结构'],
            ['id' => 'artisan', 're' => '/\bArtisan::call\s*\(/', 'level' => 'error', 'message' => '禁止在插件里调用 artisan 命令'],
            ['id' => 'route', 're' => '/\bRoute::(get|post|put|delete|any|match)\s*\(/', 'level' => 'error', 'message' => '禁止在插件里注册路由（后台接口需走平台）'],
            ['id' => 'superglobal', 're' => '/\$_GET|\$_POST|\$_REQUEST|\$_COOKIE|\$_FILES/', 'level' => 'warning', 'message' => '直接读超全局变量：插件应通过 FeatureContext 取上下文'],

            // ---- 网络外联（允许业务需要，但不能是明文传输密码等）----
            ['id' => 'verify_off', 're' => "/['\"]verify['\"]\s*=>\s*false|CURLOPT_SSL_VERIFY(HOST|PEER)\s*,\s*(0|false)/i", 'level' => 'warning', 'message' => '关闭了 HTTPS 证书校验，确认上游确实是自签证书'],

            // ---- 动态 include ----
            ['id' => 'dynamic_include', 're' => '/(include|require)(_once)?\s*\(?\s*\$/', 'level' => 'error', 'message' => '禁止动态 include/require'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function issue(string $file, int $line, string $rule, string $level, string $message): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'rule' => $rule,
            'level' => $level,
            'message' => $message,
        ];
    }
}
