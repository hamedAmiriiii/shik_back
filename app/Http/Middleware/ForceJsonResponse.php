<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * تا خطاها و اعتبارسنجی برای مسیرهای api همیشه JSON برگردند، نه صفحهٔ HTML.
 * بدون این هدر، بعضی کلاینت‌ها HTML می‌گیرند و JSON.parse خطا می‌دهد.
 */
class ForceJsonResponse
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        /* قبل از match مسیر هم اجرا شود (global stack): برای 404 و خطا، JSON نه HTML */
        if ($request->segment(1) === 'api') {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
