<?php

declare(strict_types=1);

namespace Modules\Telegram\Plugins\DemoHello;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;

/**
 * 插件模板：最小可用的自定义功能
 *
 * 命名空间规则（必须）：
 *   Modules\Telegram\Plugins\{Studly(slug)}
 *   slug = demo_hello → Studly = DemoHello
 *   目录名与命名空间段必须一致，否则审核拒绝、注册表也扫不到。
 *
 * 三类方法：
 *   - 声明类（静态）：key / label / featureName / configSchema / commands，
 *     决定后台怎么显示、表单渲染哪些字段、有哪些斜杠命令；
 *   - 业务类：handle()，命令命中后在这里写逻辑；
 *   - 可选：accessLevel() 返回 'paid' 表示需要有效套餐（默认 free）。
 *
 * 平台已封装的能力（直接用，不要自己连库/发 HTTP 请求到 Telegram）：
 *   $context->args          命令参数数组
 *   $context->value('x')    优先取命令实参，其次取已存数据
 *   $this->putData($ctx, []) 按「群 / 机器人」作用域持久化数据
 *   $this->data($ctx)       读回已存数据
 *   $this->reply() / fail() 回复文本
 */
class HelloFeature extends BaseCustomFeature
{
    /**
     * 执行器标识（写入 features.driver），插件内保持唯一
     */
    public static function key(): string
    {
        return 'plugin.demo_hello';
    }

    public static function label(): string
    {
        return '示例问候（插件）';
    }

    public static function featureName(): string
    {
        return '示例问候';
    }

    public static function featureDescription(): string
    {
        return '插件模板：/hello 名字 → 记住并回问候';
    }

    /**
     * 后台配置表单声明（前端据此自动渲染）
     *
     * type 可选：text / textarea / number / select / switch / endpoint / keyvalue / template / group_id
     */
    public static function configSchema(): array
    {
        return [
            [
                'key' => 'greeting',
                'label' => '问候语前缀',
                'type' => 'text',
                'required' => false,
                'default' => '你好',
                'hint' => '回复时拼在名字前面，可留空',
            ],
        ];
    }

    /**
     * 默认配置（首次同步写入 features.config）
     */
    public static function defaultConfig(): array
    {
        return [
            'scope_type' => 'group',
            'greeting' => '你好',
        ];
    }

    /**
     * 斜杠命令声明（写入 feature_commands）
     */
    public static function commands(): array
    {
        return [[
            'command' => 'hello',
            'usage' => '/hello 名字',
            'description' => '记住名字并回问候（插件示例）',
            'params' => [
                ['name' => 'name', 'required' => false, 'hint' => '你的名字'],
            ],
            'reply_template' => '{{data.message}}',
        ]];
    }

    /**
     * 命令命中后的业务逻辑
     */
    public function handle(FeatureContext $context): FeatureResult
    {
        $name = trim((string) $context->value('name', ''));

        if ($name === '') {
            // 没带参数时回显已记住的名字，演示数据读取
            $saved = (string) $context->value('last_name', '');

            return $saved === ''
                ? $this->fail('用法：/hello 你的名字')
                : $this->reply("又见面了，{$saved}");
        }

        // 按作用域持久化（默认群维度，scope_type 可在后台配置里改）
        $this->putData($context, ['last_name' => $name]);

        $greeting = (string) $context->config('greeting', '你好');

        return $this->reply("{$greeting}，{$name}！插件运行正常。");
    }
}
