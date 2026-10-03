<?php

namespace App\Http\Middleware;

use App\Models\RepairUser;
use Closure;
use Illuminate\Http\Request;

/**
 * توکن کاربران سامانهٔ تعمیرکار فقط روی مسیرهای api/repair معتبر است.
 */
class IsolateRepairTokens
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user('sanctum') instanceof RepairUser && ! $request->is('api/repair', 'api/repair/*')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
