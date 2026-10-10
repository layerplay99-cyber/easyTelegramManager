<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\RealMan;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\Feature\Drivers\TelegramApiDriver;

/**
 * 真人功能基类
 *
 * 真人与机器人是两条链路：这里的功能全部由真人账号（app_id/app_hash/session）执行。
 *
 * 执行器层面「共用一套」：所有真人功能的 features.driver 都是 telegram.api
 * （TelegramApiDriver），具体动作由 config.method 区分——后台改 method 就能换动作，
 * 不需要为每个动作新建一个执行器。
 *
 * 子类只需声明：
 *   - featureKey() 功能标识（入库为 custom:{featureKey}）
 *   - featureName() 功能名称（后台功能列表显示）
 *   - method()     真人操作（TelegramApiDriver::METHODS 的键）
 *
 * 新增一个真人功能 = 在 RealMan/ 下加一个类，执行 php artisan telegram:sync-features
 * （或打开后台功能列表自动扫描）即入库，后台可改配置与绑定。
 */
abstract class AbstractRealManFeature extends BaseCustomFeature
{
    /**
     * 真人操作（群发 / 私发 / @ / 回复 / 踢人 / 采集）
     */
    abstract public static function method(): string;

    /**
     * 共用真人驱动：不在后台执行器下拉里重复出现
     */
    public static function driver(): string
    {
        return TelegramApiDriver::key();
    }

    public static function isDriver(): bool
    {
        return false;
    }

    /**
     * 仅作为功能代码的身份标识，不参与执行器注册
     */
    public static function key(): string
    {
        return 'realman.feature';
    }

    public static function label(): string
    {
        return '真人功能';
    }

    public static function category(): string
    {
        return 'realMan';
    }

    public static function group(): string
    {
        return '真人功能';
    }

    public static function triggerName(): string
    {
        return 'manual';
    }

    public static function triggers(): array
    {
        return ['manual'];
    }

    /**
     * 真人功能由后台「真人账号」页面触发，不挂斜杠命令
     */
    public static function commands(): array
    {
        return [];
    }

    /**
     * 后台配置表单直接复用真人驱动的表单
     */
    public static function configSchema(): array
    {
        return TelegramApiDriver::configSchema();
    }

    public static function defaultConfig(): array
    {
        return [
            'method' => static::method(),
            'message_type' => 'text',
        ];
    }

    public static function featureDescription(): string
    {
        return '以真人账号身份执行：' . (TelegramApiDriver::METHODS[static::method()] ?? static::method());
    }

    /**
     * 统一委托给真人驱动：凭证解析、chatIds 拆分、派发任务都在驱动里
     */
    public function handle(FeatureContext $context): FeatureResult
    {
        return app(TelegramApiDriver::class)->execute($context);
    }
}
