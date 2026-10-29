<?php

namespace App\Http\Middleware;

use App\Models\BilliardUser;
use Closure;
use Illuminate\Http\Request;

/**
 * مسیرهای سامانهٔ بیلیارد: فقط توکن BilliardUser با نقش مجاز.
 * مثال: billiard.role:admin یا billiard.role:operator,admin
 */
class EnsureBilliardRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        if (! $user instanceof BilliardUser) {
            return response()->json(['message' => 'ابتدا وارد سامانهٔ مسابقات شوید.'], 401);
        }
        if (! $user->is_active) {
            return response()->json(['message' => 'حساب شما غیرفعال است.'], 403);
        }
        if ($roles !== [] && ! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'به این بخش دسترسی ندارید.'], 403);
        }

        return $next($request);
    }
}
