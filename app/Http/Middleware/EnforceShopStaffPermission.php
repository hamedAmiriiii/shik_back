<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ShopPermissionCatalog;
use App\Services\ShopStaffAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * کارمند فروشگاه فقط APIهایی را می‌زند که در لیست دسترسی‌اش باشد.
 * صاحب فروشگاه و ادمین سامانه محدود نمی‌شوند.
 * نقش‌های عروسی/ادمین به این دسترسی‌ها ربطی ندارند.
 */
class EnforceShopStaffPermission
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('sanctum');
        if (! $user instanceof User) {
            return $next($request);
        }
        if (ShopStaffAccess::isOwner($user) || ShopStaffAccess::isPlatformAdmin($user)) {
            return $next($request);
        }
        if (! ShopStaffAccess::isShopStaff($user)) {
            return $next($request);
        }

        $loginError = ShopStaffAccess::assertStaffMayLogin($user);
        if ($loginError !== null) {
            return response()->json(['message' => $loginError, 'error' => $loginError], 403);
        }

        $permission = ShopPermissionCatalog::permissionForPath($request->path());
        if ($permission === null) {
            return $next($request);
        }
        if (ShopStaffAccess::can($user, $permission)) {
            return $next($request);
        }

        return response()->json(
            ShopPermissionCatalog::deniedPayload($permission, $request->method()),
            403
        );
    }
}
