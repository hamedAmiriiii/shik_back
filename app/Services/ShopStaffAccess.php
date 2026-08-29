<?php

namespace App\Services;

use App\Models\ShopEmployee;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class ShopStaffAccess
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_STAFF = 'staff';

    public static function isPlatformAdmin(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->roles()->where('id', User::USER_TYPE_KEY['ادمین'])->exists();
    }

    public static function isOwner(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if (($user->shop_staff_role ?? null) === self::ROLE_STAFF) {
            return false;
        }
        if (self::isPlatformAdmin($user)) {
            return true;
        }
        $role = $user->shop_staff_role;
        if ($role === self::ROLE_OWNER) {
            return true;
        }
        if (! $user->atelier_id) {
            return false;
        }

        if (! Schema::hasTable('shop_employees') || ! Schema::hasColumn('shop_employees', 'user_id')) {
            return true;
        }

        return ! ShopEmployee::where('user_id', $user->id)->exists();
    }

    public static function isShopStaff(?User $user): bool
    {
        if (! $user || ! $user->atelier_id) {
            return false;
        }

        return $user->shop_staff_role === self::ROLE_STAFF
            || ShopEmployee::query()->where('user_id', $user->id)->exists();
    }

    /**
     * @return array<int, string>
     */
    public static function permissionKeysFor(?User $user): array
    {
        if (! $user) {
            return [];
        }
        if (self::isOwner($user)) {
            return ShopPermissionCatalog::keys();
        }

        $employee = self::employeeFor($user);
        if (! $employee || ! $employee->is_active) {
            return [];
        }

        $raw = $employee->permissions;
        if (! is_array($raw)) {
            return [];
        }

        return ShopPermissionCatalog::sanitize($raw);
    }

    public static function can(?User $user, string $permission): bool
    {
        if (self::isOwner($user)) {
            return true;
        }

        return in_array($permission, self::permissionKeysFor($user), true);
    }

    public static function employeeFor(?User $user): ?ShopEmployee
    {
        if (! $user || ! Schema::hasTable('shop_employees') || ! Schema::hasColumn('shop_employees', 'user_id')) {
            return null;
        }

        return ShopEmployee::where('user_id', $user->id)->first();
    }

    public static function assertStaffMayLogin(User $user): ?string
    {
        if (! self::isShopStaff($user) || self::isOwner($user)) {
            return null;
        }
        $employee = self::employeeFor($user);
        if (! $employee) {
            return 'حساب کارمندی شما یافت نشد.';
        }
        if (! $employee->is_active) {
            return 'حساب شما غیرفعال است. با صاحب فروشگاه تماس بگیرید.';
        }

        return null;
    }

    /**
     * فیلدهای نشست برای پاسخ لاگین /user.
     *
     * @return array<string, mixed>
     */
    public static function sessionFields(User $user): array
    {
        $keys = self::permissionKeysFor($user);
        $owner = self::isOwner($user);
        $employee = self::employeeFor($user);

        return [
            'shop_is_owner' => $owner,
            'shop_permissions' => $keys,
            'shop_employee_id' => $employee ? (int) $employee->id : null,
        ];
    }
}
