<?php

namespace Modules\Common\Repository\Options;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class Components implements OptionInterface
{
    /**
     * @var array|string[]
     */
    protected array $components = [
        [
            'label' => 'layout',
            'value' => '/layout/index.vue',
        ],
    ];

    public function get(): array
    {
        try {
            $viewRootPath = config('catch.views_path');

            if ($module = request()->get('module')) {
                // module 直接取自请求参数，传入 "../../.." 会遍历任意目录，
                // 这里限制为纯字母开头的合法模块名。
                if (! is_string($module) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $module)) {
                    return $this->components;
                }

                if (!File::exists($viewRootPath . $module . DIRECTORY_SEPARATOR)) {
                    return [];
                }

                $components = File::allFiles($viewRootPath . $module . DIRECTORY_SEPARATOR);

                foreach ($components as $component) {
                    // 过滤非 vue 文件
                    if ($component->getExtension() !== 'vue') {
                        continue;
                    }

                    $_component = Str::of($component->getPathname())
                        ->replace($viewRootPath, '')
                        ->explode(DIRECTORY_SEPARATOR);

                    $_component->shift(1);

                    $this->components[] = [
                        'label' => Str::of($_component->implode('/'))->replace('.vue', ''),

                        'value' => Str::of($component)->replace($viewRootPath, '')->prepend('/'),
                    ];
                }
            }

            return $this->components;
        } catch (\Throwable $exception) {
            return [];
        }
    }
}
