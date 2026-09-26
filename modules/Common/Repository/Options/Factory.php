<?php

namespace Modules\Common\Repository\Options;

use Exception;
use Illuminate\Support\Str;

class Factory
{
    /**
     * make
     * @param string $optionName
     * @return OptionInterface
     * @throws Exception
     */
    public function make(string $optionName): OptionInterface
    {
        // 原来直接用 URL 里的 {name} 拼类名：__NAMESPACE__.'\\'.ucfirst($name)。
        // 传入 "..\..\Other\Class" 之类的值就能跳出本命名空间实例化任意类。
        // 这里只拦「命名空间穿越字符」，不限制具体有哪些 option，
        // 因此不改变框架原有的调用方式，也不会误伤合法的 option 名。
        if (! preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $optionName)) {
            throw new Exception('option must be implement [OptionInterface]');
        }

        $className = __NAMESPACE__.'\\'.Str::of($optionName)->ucfirst()->toString();

        if (! class_exists($className)) {
            throw new Exception('option must be implement [OptionInterface]');
        }

        $class = new $className();

        if (! $class instanceof OptionInterface) {
            throw new Exception('option must be implement [OptionInterface]');
        }

        return $class;
    }
}
