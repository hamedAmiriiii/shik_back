<?php

namespace App\Services;

use App\Models\ShopEmployee;
use App\Models\User;
use App\Tools\ImageTools;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ShopEmployeeAccountService
{
    private const PLACEHOLDER_JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwABmX/9k=';

    /**
     * ساخت یا به‌روزرسانی یوزر ورود کارمند. نام کاربری همان شماره موبایل است.
     */
    public static function syncLogin(ShopEmployee $employee, int $atelierId, ?string $password): User
    {
        $phone = $employee->phone;
        if (! is_string($phone) || $phone === '') {
            throw new RuntimeException('برای ورود کارمند باید شماره موبایل (نام کاربری) ثبت شود.');
        }

        $user = null;
        if ($employee->user_id) {
            $user = User::find($employee->user_id);
        }
        if (! $user) {
            $user = User::where('phone', $phone)->first();
        }

        if ($user) {
            if ((int) $user->atelier_id !== $atelierId && $user->atelier_id) {
                throw new RuntimeException('این شماره موبایل قبلاً برای فروشگاه دیگری ثبت شده است.');
            }
            if ($user->shop_staff_role === ShopStaffAccess::ROLE_OWNER && (int) $user->atelier_id === $atelierId) {
                throw new RuntimeException('نمی‌توان برای صاحب فروشگاه حساب کارمندی جدا ساخت. از همان ورود صاحب فروشگاه استفاده کنید.');
            }
            $taken = ShopEmployee::query()
                ->where('user_id', $user->id)
                ->where('id', '!=', $employee->id)
                ->exists();
            if ($taken) {
                throw new RuntimeException('این شماره قبلاً به کارمند دیگری وصل است.');
            }
        } else {
            if (! $password) {
                throw new RuntimeException('برای اولین ورود کارمند باید رمز عبور تعیین شود.');
            }
            $user = self::createStaffUser($employee, $atelierId, $password);
            $password = null;
        }

        $payload = [
            'name' => $employee->name,
            'phone' => $phone,
            'atelier_id' => $atelierId,
            'shop_staff_role' => ShopStaffAccess::ROLE_STAFF,
        ];
        if ($password) {
            $payload['password'] = Hash::make($password);
        }
        $user->fill($payload);
        $user->save();

        if ($password) {
            $user->tokens()->delete();
        }

        $shopRoleId = User::USER_TYPE_KEY['فروشگاه'];
        if (! $user->roles()->where('id', $shopRoleId)->exists()) {
            $user->roles()->attach($shopRoleId);
        }

        if ((int) $employee->user_id !== (int) $user->id) {
            $employee->user_id = $user->id;
            $employee->save();
        }

        return $user;
    }

    public static function revokeLogin(ShopEmployee $employee): void
    {
        if (! $employee->user_id) {
            return;
        }
        $user = User::find($employee->user_id);
        if ($user && $user->shop_staff_role === ShopStaffAccess::ROLE_STAFF) {
            $user->tokens()->delete();
        }
    }

    public static function deleteLoginUser(ShopEmployee $employee): void
    {
        if (! $employee->user_id) {
            return;
        }
        $user = User::find($employee->user_id);
        $employee->user_id = null;
        $employee->save();
        if ($user && $user->shop_staff_role === ShopStaffAccess::ROLE_STAFF) {
            $user->tokens()->delete();
            $user->roles()->detach();
            $user->delete();
        }
    }

    private static function createStaffUser(ShopEmployee $employee, int $atelierId, string $password): User
    {
        $nationalCode = self::uniqueNationalCode($employee->phone);
        $attrs = [
            'name' => $employee->name,
            'last_name' => '—',
            'phone' => $employee->phone,
            'national_code' => $nationalCode,
            'password' => Hash::make($password),
            'atelier_id' => $atelierId,
            'shop_staff_role' => ShopStaffAccess::ROLE_STAFF,
            'national_cart' => '-',
        ];
        if (Schema::hasColumn('users', 'gender')) {
            $attrs['gender'] = null;
        }

        $jpeg = base64_decode(self::PLACEHOLDER_JPEG, true) ?: '';
        foreach ([
            'personality_image' => $nationalCode.'/staff_personality.jpeg',
            'birth_certificate' => $nationalCode.'/staff_birth.jpeg',
            'tech_certificate' => $nationalCode.'/staff_tech.jpeg',
            'national_cart' => $nationalCode.'/staff_national_cart.jpeg',
        ] as $column => $path) {
            if (Schema::hasColumn('users', $column)) {
                $attrs[$column] = ImageTools::saveFile($path, $jpeg);
            }
        }

        return User::create($attrs);
    }

    private static function uniqueNationalCode(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?: '0';
        $base = str_pad(substr($digits, -10), 10, '0', STR_PAD_LEFT);
        for ($i = 0; $i < 500; $i++) {
            $candidate = $base;
            if ($i > 0) {
                $suffix = str_pad((string) (($i % 99) + 1), 2, '0', STR_PAD_LEFT);
                $candidate = substr($base, 0, 8).$suffix;
            }
            if (! User::where('national_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return str_pad((string) (time() % 10000000000), 10, '0', STR_PAD_LEFT);
    }
}
