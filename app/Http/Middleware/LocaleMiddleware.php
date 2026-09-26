<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;

class LocaleMiddleware
{
    public function handle($request, Closure $next)
    {
        $locale = $request->header('accept-language');
        if ($locale === 'zh') {
            $locale = 'zh_CN';
        }
        $availableLocales = ['zh_CN', 'en', 'th', 'vi'];
        if ($locale) {
            // 只取第一个语言代码，并去除地区后缀和优先级
            $locale = strtolower(explode(',', $locale)[0]);
            $locale = str_replace('-', '_', $locale);
            $shortLocale = explode('_', $locale)[0];

            if (in_array($locale, $availableLocales)) {
                App::setLocale($locale);
            } elseif (in_array($shortLocale, $availableLocales)) {
                App::setLocale($shortLocale);
            } else {
                App::setLocale(config('app.locale'));
            }
        } else {
            App::setLocale(config('app.locale'));
        }
        return $next($request);
    }
}
