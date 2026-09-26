<?php
declare(strict_types=1);

namespace Modules\Telegram\Contracts;

use Modules\Telegram\Services\Feature\Command\CommandContext;

/**
 * 斜杠命令接口
 *
 * 想新增一条 Telegram 斜杠命令，只需要：
 *   1. 在 modules/Telegram/Services/Feature/Command 下新建类 implements 本接口
 *      （推荐 extends BaseSlashCommand，只写 name/description/handle 三个方法）
 *   2. 在后台「功能管理」里新增记录：
 *      - handler     填完整类名
 *      - feature     填命令名（不带斜杠，大小写不敏感）
 *      - description 命令说明（会自动同步接口里声明的文案）
 *      - config      按 configSchema() 声明的配置项填（上游 API、校验规则等）
 *      - enabled     开关
 *   3. 把该命令绑定到目标群即可使用
 *
 * 分发、参数校验、错误兜底、日志都由 SlashCommandDispatcher 统一处理，
 * 具体命令类只负责业务。
 */
interface SlashCommand
{
    /**
     * 命令名（小写，不含斜杠），如 'ye'、'cx'
     */
    public function name(): string;

    /**
     * 命令说明，后台展示 / 同步到 Telegram 命令菜单
     */
    public function description(): string;

    /**
     * 用法示例，参数缺失时回给用户
     */
    public function usage(): string;

    /**
     * 参数定义，供后台展示并在分发时做必填校验
     *
     * 每项：[
     *   'name'        => 'order_id',
     *   'required'    => true,
     *   'description' => '订单号',
     *   'rule'        => '/^\d+$/',   // 可选，正则校验
     * ]
     */
    public function params(): array;

    /**
     * 后台配置项定义（上游 API、校验规则等）
     *
     * 每项：[
     *   'key'     => 'thridconfig',
     *   'label'   => '上游 API 配置',
     *   'type'    => 'text|select|number|json',
     *   'required'=> true,
     *   'default' => ...,
     *   'options' => [],   // type=select 时的候选
     * ]
     */
    public function configSchema(): array;

    /**
     * 执行命令，返回要回复给用户的文本
     */
    public function handle(CommandContext $context): string;
}
