<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ShopAuditorAccess;
use App\Services\ShopPermissionCatalog;
use App\Services\ShopStaffAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * کارمند فروشگاه فقط APIهایی را می‌زند که در لیست دسترسی‌اش باشد.
 * حسابرس فقط مشاهده می‌کند و فقط در فروشگاهی که انتخاب کرده.
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
        if (ShopStaffAccess::isPlatformAdmin($user)) {
            return $next($request);
        }
        if (ShopStaffAccess::isAuditor($user)) {
            return $this->handleAuditor($request, $next, $user);
        }
        if (ShopStaffAccess::isOwner($user)) {
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

    private function handleAuditor(Request $request, Closure $next, User $user)
    {
        $path = ShopPermissionCatalog::normalizePath($request->path());
        if (ShopPermissionCatalog::pathStartsWith($path, ShopAuditorAccess::SHOP_FREE_PREFIXES)) {
            return $next($request);
        }

        $link = ShopAuditorAccess::activeLink($user);
        if (! $link) {
            $message = 'ابتدا فروشگاهی را که می‌خواهید بررسی کنید انتخاب کنید.';

            return response()->json([
                'message' => $message,
                'error' => $message,
                'requires_shop_selection' => true,
            ], 403);
        }

        $readMethod = in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
        if (! $readMethod && ! ShopPermissionCatalog::pathStartsWith($path, ShopAuditorAccess::READ_ONLY_POST_PREFIXES)) {
            $message = 'حساب حسابرس فقط اجازهٔ مشاهده دارد و نمی‌تواند چیزی ثبت، ویرایش یا حذف کند.';

            return response()->json([
                'message' => $message,
                'error' => $message,
                'read_only' => true,
            ], 403);
        }

        $permission = ShopPermissionCatalog::permissionForPath($path);
        if ($permission !== null && ! in_array($permission, ShopAuditorAccess::permissionKeys($link), true)) {
            return response()->json(
                ShopPermissionCatalog::deniedPayload($permission, $request->method()),
                403
            );
        }

        return $next($request);
    }
}
