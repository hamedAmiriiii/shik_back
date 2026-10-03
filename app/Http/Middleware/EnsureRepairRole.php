<?php

namespace App\Http\Middleware;

use App\Models\RepairUser;
use Closure;
use Illuminate\Http\Request;

/**
 * مسیرهای سامانهٔ تعمیرکار: فقط توکن RepairUser با نقش مجاز.
 * مثال: repair.role:admin یا repair.role:technician,admin
 */
class EnsureRepairRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        if (! $user instanceof RepairUser) {
            return response()->json(['message' => 'ابتدا وارد سامانهٔ تعمیرکار شوید.'], 401);
        }
        if (! $user->is_active) {
            return response()->json(['message' => 'حساب شما غیرفعال است.'], 403);
        }
        if ($user->isTechnician() && ! $user->isApproved()) {
            return response()->json(['message' => 'حساب شما در انتظار تأیید مدیر است.'], 403);
        }
        if ($roles !== [] && ! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'به این بخش دسترسی ندارید.'], 403);
        }

        return $next($request);
    }
}
