<?php

namespace Modules\Common\Repository\Options;

use Catch\CatchAdmin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class Controllers implements OptionInterface
{
    public function get(): array
    {
        $controllers = [];

        if ($module = request()->get('module')) {
            // 同 Components：限制模块名字符，避免路径穿越
            if (! is_string($module) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $module)) {
                return $controllers;
            }

            $controllerFiles = File::glob(CatchAdmin::getModuleControllerPath($module).'*.php');

            foreach ($controllerFiles as $controllerFile) {
                $controllers[] = [
                    'label' => Str::of(File::name($controllerFile))->lcfirst()->remove('Controller'),

                    'value' => Str::of(File::name($controllerFile))->lcfirst()->remove('Controller'),
                ];
            }
        }

        return $controllers;
    }
}
