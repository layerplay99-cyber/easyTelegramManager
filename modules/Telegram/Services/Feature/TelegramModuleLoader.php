<?php

namespace Modules\Telegram\Services\Feature;

class TelegramModuleLoader
{
    protected $modules = [];

    public function loadModules()
    {
        $basePath = base_path('modules/Telegram/Services/Feature');
        $baseNamespace = 'Modules\\Telegram\\Services\\Feature\\';

        $folders = ['Callback', 'InlineQuery'];

        foreach ($folders as $folder) {
            $path = $basePath . '/' . $folder;
            foreach (glob($path . '/*.php') as $file) {
                $class = $baseNamespace . $folder . '\\' . basename($file, '.php');

                if (! class_exists($class)) {
                    continue;
                }

                // 原来直接 new $class：命令类构造函数里的依赖注入（AiopayService 等）全部失效。
                // 改为经容器解析；解析失败（缺依赖/抽象类）则跳过，避免整个交互流程挂掉。
                try {
                    $this->modules[$class] = app($class);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("模块 {$class} 实例化失败: " . $e->getMessage());
                }
            }
        }

        return $this->modules;
    }
}
