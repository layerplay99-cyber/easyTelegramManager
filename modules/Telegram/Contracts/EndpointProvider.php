<?php

declare(strict_types=1);

namespace Modules\Telegram\Contracts;

/**
 * 平台接口声明者
 *
 * 谁要用上游接口，谁就自己声明这些接口的规范——不要把定义堆到
 * PlatformEndpointRegistry 里（几十上百个功能全塞一个文件必然失控）。
 *
 * 用法：在 Driver / 功能类 / Gateway 上实现这个接口，返回自己需要的接口定义，
 * PlatformEndpointRegistry 会自动扫描汇总，后台下拉与同步命令随即生效。
 */
interface EndpointProvider
{
    /**
     * 本类依赖/提供的平台接口
     *
     * @return array<string, array<string, mixed>> 以 code 为键，值为：
     *                                              name / method / path_template /
     *                                              params_schema / response_schema / remark
     */
    public static function endpoints(): array;
}
