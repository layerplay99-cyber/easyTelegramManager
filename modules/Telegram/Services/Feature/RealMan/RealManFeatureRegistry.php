<?php

namespace Modules\Telegram\Services\Feature\RealMan;

use Modules\Telegram\Interface\RealManFeature;

/**
 * 真实用户特性注册表
 *
 * 自动扫描当前目录，登记所有实现了 RealManFeature 的特性类。
 * 键名 = 类短名（如 SendMsgToGroups），与前端 operateFeature 传入的 operation 参数一致。
 *
 * 新增一个真实用户特性：只需在 RealMan 目录下新建一个类并继承 AbstractRealManFeature，
 * 实现 handle() 即可，无需手动维护此映射（与 Bot 侧的 SlashCommandRegistry 思路一致）。
 *
 * 构造契约（operateFeature 会用 TelegramApiUsers 的凭证三元组实例化）：
 *   由 AbstractRealManFeature 统一提供，固定为 (string $sessionFile, $appId, $appHash)。
 */
class RealManFeatureRegistry
{
    public static function allFeatures(): array
    {
        $map = [];

        foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
            $shortName = basename($file, '.php');

            if ($shortName === 'RealManFeatureRegistry') {
                continue;
            }

            $className = __NAMESPACE__ . '\\' . $shortName;

            if (! class_exists($className) || ! is_subclass_of($className, RealManFeature::class)) {
                continue;
            }

            // 跳过抽象基类（如 AbstractRealManFeature），它实现了接口但本身不是可实例化的特性
            if ((new \ReflectionClass($className))->isAbstract()) {
                continue;
            }

            $map[$shortName] = $className;
        }

        return $map;
    }
}
