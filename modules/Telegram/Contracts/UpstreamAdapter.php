<?php

declare(strict_types=1);

namespace Modules\Telegram\Contracts;

use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Upstream\UpstreamRequest;
use Modules\Telegram\Services\Upstream\UpstreamResponse;
use Modules\Telegram\Services\Upstream\UpstreamResult;

/**
 * 上游协议适配器（每家上游厂商一个实现类）
 *
 * 立场：平台只说「标准语言」，厂商的私有协议由各自的适配器吸收。
 *   - 功能代码（查余额 / 绑商户 / 限额 / OCR …）和结构一律按平台标准写，
 *     不知道也不关心当前接的是哪一家上游；
 *   - 适配器只管「协议」，不管业务：参数叫什么、放在哪、怎么签名、
 *     响应包在哪层、哪个字段代表成功、字段叫什么、状态值怎么翻译；
 *   - base_url / token / 密钥 / path / method 这些「同一厂商不同部署」的
 *     参数继续留在后台配置，不写进代码。
 *
 * 两个钩子，分别在发请求前、拿到响应后：
 *   prepareRequest()  标准参数 → 该厂商实际要发的参数
 *   parseResponse()   该厂商原始响应 → 平台标准结构 + 成功判定
 *
 * 接入方式：往 config/upstream_adapters.php 配的目录里丢一个实现类即可。
 * 后台「三方配置 → 上游适配器」下拉会自动出现，不用注册、不用清缓存。
 * 在三方配置上选中后，该上游的所有出站请求都会走这个适配器。
 *
 * 兼容性：key 为空 / 类被删掉 / 类抛异常时，注册表会回退到 DefaultAdapter，
 * 行为与接插件之前完全一致（原样发、原样返回、只看 HTTP 状态判成功）。
 * 所以接插件永远不会让一条正在跑的业务链路整体挂掉。
 *
 * @see \Modules\Telegram\Services\Upstream\Adapters\DefaultAdapter 直通基线
 * @see \Modules\Telegram\Services\Upstream\Adapters\Demo 四个教学示例
 */
interface UpstreamAdapter
{
    /**
     * 稳定标识，存进 thirdapi_config.adapter
     *
     * 必须是小写字母 / 数字 / 点 / 下划线，且全局唯一（同名后扫到的会覆盖）。
     * 一旦有上游在用就不要改，改了会找不到类而回退默认 —— 业务不会报错，
     * 但字段映射会悄悄失效。
     */
    public static function key(): string;

    /**
     * 后台下拉里显示的名称，建议带上厂商名，例如「示例 · 某某支付」
     */
    public static function label(): string;

    /**
     * 后台下拉里显示的描述，一句话说清这个适配器处理了哪些差异
     */
    public static function description(): string;

    /**
     * 请求前：把平台标准参数翻译成该厂商要的样子
     *
     * 典型操作：字段改名、加固定值、把参数从 body 挪到 header、
     * 切 form 编码、算签名塞进 params/headers、改 method。
     * 不做业务判断，不发请求，不查数据库。
     *
     * 注意：$request->url / path 此时已经拼好，一般不用动；
     * 若厂商要求不同的路径，直接改 $request->path（调用方会据此重新拼 URL），
     * 不要自己改 $request->url。
     *
     * @param ThirdApiConfig $upstream 当前上游实例（可取 public_key / secrept_key / channel_id 等凭证）
     */
    public function prepareRequest(UpstreamRequest $request, ThirdApiConfig $upstream): UpstreamRequest;

    /**
     * 响应后：把该厂商的原始响应归一到平台标准结构
     *
     * 典型操作：判断业务成功码（很多厂商是 HTTP 200 + body.code != 0）、
     * 解包 data/result、字段别名、状态值翻译、金额单位换算、提取错误消息。
     *
     * 返回的 UpstreamResult.data 是「平台标准结构」，功能代码和回复模板读它；
     * raw 保留原始响应，供排查问题用（模板里也能用 _raw 取到）。
     *
     * 这里不要发通知、不要写库、不要抛异常给业务层 —— 失败请返回
     * UpstreamResult::fail()，由调用方统一按 _error 处理。
     */
    public function parseResponse(
        UpstreamResponse $response,
        UpstreamRequest $request,
        ThirdApiConfig $upstream
    ): UpstreamResult;
}
