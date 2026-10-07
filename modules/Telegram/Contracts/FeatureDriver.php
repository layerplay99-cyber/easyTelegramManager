<?php

declare(strict_types=1);

namespace Modules\Telegram\Contracts;

/**
 * 功能执行器（Driver）
 *
 * 无代码路线的核心：后台「功能列表」不写 handler 类名、不写 JSON，
 * 而是由执行器自己声明可配置项（configSchema），前端据此自动渲染表单。
 *
 * 新增一个功能 = 实现一个 Driver（多数情况直接复用内置的几个），
 * 后台会自动出现对应选项与表单，无需改前端。
 */
interface FeatureDriver
{
    /**
     * 执行器唯一标识（存 features.driver）
     */
    public static function key(): string;

    /**
     * 后台显示名
     */
    public static function label(): string;

    /**
     * 所属分组，用于后台按「四大块」归类展示
     */
    public static function group(): string;

    /**
     * 该执行器支持哪些触发方式
     *
     * @return array<int, string> command|callback_query|webhook|manual
     */
    public static function triggers(): array;

    /**
     * 配置项声明（前端据此自动渲染表单）
     *
     * 每个字段：
     *   key         字段名
     *   label       显示名
     *   type        text|textarea|number|select|switch|endpoint|keyvalue|template|group_id
     *   source      选项来源（type=select/endpoint 时），如 third_api_endpoints
     *   required    是否必填
     *   default     默认值
     *   hint        提示文案
     *   depends_on  条件显示，如 ['driver' => 'http.request']
     *
     * @return array<int, array<string, mixed>>
     */
    public static function configSchema(): array;

    /**
     * 校验配置，返回错误信息数组（空数组 = 通过）
     *
     * @param  array<string, mixed> $config
     * @return array<int, string>
     */
    public function validate(array $config): array;

    /**
     * 执行功能
     */
    public function execute(FeatureContext $context): FeatureResult;
}