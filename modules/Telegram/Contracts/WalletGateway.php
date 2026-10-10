<?php

declare(strict_types=1);

namespace Modules\Telegram\Contracts;

use Modules\Telegram\Models\ThirdApiConfig;

/**
 * 钱包网关契约（充值 / 提现 / 查单 / 余额）
 *
 * 「具体怎么跟某家上游打交道」由实现决定：平台只认这个契约。
 * 想接新上游 = 写一个实现类并在 config/wallet.php 的 gateway 里换掉即可，
 * 不需要动订单、钱包、风控、功能代码任何一处。
 *
 * 上游必须配置的 4 项（后台「三方配置」里）：
 *   拉单 API / 查单 API —— 在「上游接口配置」里按 wallet.*.create / wallet.*.query 覆盖路径
 *   key                 —— ThirdApiConfig.public_key
 *   密钥                —— ThirdApiConfig.secrept_key
 *   通道ID              —— ThirdApiConfig.channel_id
 *
 * 统一的返回结构（所有方法一致，便于上层编排）：
 *   [
 *     'success'   => bool,          // 请求是否成功（HTTP 通 + 业务成功）
 *     'status'    => 'pending|paid|success|failed',
 *     'order_no'  => '平台订单号',
 *     'third_order_no' => '上游订单号',
 *     'pay_url'   => '支付链接（充值拉单用）',
 *     'amount'    => 实际金额,
 *     'message'   => '失败原因',
 *     'raw'       => 上游原始响应,
 *   ]
 */
interface WalletGateway
{
    /**
     * 充值拉单
     *
     * @param array $params order_no / amount / currency / channel_id / notify_url / 业务自定义字段
     */
    public function createRecharge(ThirdApiConfig $upstream, array $params): array;

    /**
     * 充值查单
     *
     * @param array $params order_no / third_order_no
     */
    public function queryRecharge(ThirdApiConfig $upstream, array $params): array;

    /**
     * 提现拉单（代付）
     *
     * @param array $params order_no / amount / currency / channel_id / account / notify_url
     */
    public function createWithdraw(ThirdApiConfig $upstream, array $params): array;

    /**
     * 提现查单
     *
     * @param array $params order_no / third_order_no
     */
    public function queryWithdraw(ThirdApiConfig $upstream, array $params): array;

    /**
     * 查询上游商户余额
     */
    public function balance(ThirdApiConfig $upstream, array $params = []): array;

    /**
     * 校验上游回调签名（防伪造回调入账）
     */
    public function verifyCallback(ThirdApiConfig $upstream, array $payload): bool;

    /**
     * 把上游的状态字段翻译成平台状态：pending / paid / success / failed
     */
    public function resolveStatus(array $payload): string;
}
