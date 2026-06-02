<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * کد فروشگاه را از پارامتر مسیر {shop} (مثلاً milito) به درخواست اضافه می‌کند
 * تا shopAtelierIdOrAbort بدون ارسال دستی atelier_code کار کند.
 */
class BindShopFromRoute
{
    public function handle(Request $request, Closure $next)
    {
        $shop = $request->route('shop');
        if (! is_string($shop) || $shop === '') {
            return $next($request);
        }

        $shop = trim($shop);

        if (! $request->header('X-Atelier-Code')) {
            $request->headers->set('X-Atelier-Code', $shop);
        }

        if (! $request->query('atelier_code') && ! $request->input('atelier_code')) {
            $request->merge(['atelier_code' => $shop]);
        }

        return $next($request);
    }
}
