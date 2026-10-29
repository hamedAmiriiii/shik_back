<?php

namespace App\Http\Middleware;

use App\Models\BilliardUser;
use Closure;
use Illuminate\Http\Request;

/**
 * توکن کاربران سامانهٔ بیلیارد فقط روی مسیرهای api/billiard معتبر است.
 */
class IsolateBilliardTokens
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user('sanctum') instanceof BilliardUser && ! $request->is('api/billiard', 'api/billiard/*')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
