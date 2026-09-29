<?php

namespace App\Services;

use App\Models\ShopAuditor;
use App\Models\User;
use App\Support\ProjectType;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * حسابرس: یک حساب که به چند فروشگاه وصل است و فقط می‌تواند ببیند.
 * فروشگاه فعال حسابرس همان users.atelier_id است و با انتخاب فروشگاه عوض می‌شود.
 */
class ShopAuditorAccess
{
    /**
     * مسیرهایی که بدون انتخاب فروشگاه هم در دسترس‌اند.
     *
     * @var array<int, string>
     */
    public const SHOP_FREE_PREFIXES = [
        'user',
        'auditor',
        'shop-access',
        'shop-permissions',
        'auth',
        'geo',
        'reset-password',
        'atelier/profile/reset-password',
    ];

    /**
     * درخواست‌های POST که فقط محاسبه/پیش‌نمایش‌اند و چیزی ثبت نمی‌کنند.
     *
     * @var array<int, string>
     */
    public const READ_ONLY_POST_PREFIXES = [
        'purchased-products/calculate-installments',
        'shop-sms-quota/estimate',
    ];

    /**
     * بخش‌هایی که حسابرس هرگز نمی‌گیرد (خروجی کامل دیتابیس فروشگاه).
     *
     * @var array<int, string>
     */
    public const EXCLUDED_PERMISSION_KEYS = ['backup'];

    public static function tableReady(): bool
    {
        return Schema::hasTable('shop_auditors');
    }

    public static function activeLink(User $user, ?int $atelierId = null): ?ShopAuditor
    {
        if (! self::tableReady()) {
            return null;
        }
        $atelierId = $atelierId ?? ($user->atelier_id ? (int) $user->atelier_id : null);
        if (! $atelierId) {
            return null;
        }

        return ShopAuditor::query()
            ->where('user_id', $user->id)
            ->where('atelier_id', $atelierId)
            ->where('is_active', true)
            ->whereHas('atelier')
            ->first();
    }

    /**
     * @return array<int, array{atelier_id: int, code: string|null, name: string|null, auditor_label: string|null, shop_access_active: bool}>
     */
    public static function shopsFor(User $user): array
    {
        if (! self::tableReady()) {
            return [];
        }

        return ShopAuditor::query()
            ->with('atelier')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('atelier')
            ->orderBy('id')
            ->get()
            ->map(function (ShopAuditor $link) {
                $atelier = $link->atelier;

                return [
                    'atelier_id' => (int) $atelier->id,
                    'code' => $atelier->code,
                    'name' => $atelier->name,
                    'auditor_label' => $link->name,
                    'shop_access_active' => $atelier->isShopAccessActive(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function permissionKeys(?ShopAuditor $link): array
    {
        if (! $link) {
            return [];
        }
        $keys = is_array($link->permissions)
            ? ShopPermissionCatalog::sanitize($link->permissions)
            : ShopPermissionCatalog::keys();

        return array_values(array_diff($keys, self::EXCLUDED_PERMISSION_KEYS));
    }

    /**
     * فروشگاه فعال حسابرس را عوض می‌کند؛ اگر اتصال فعالی نباشد null.
     */
    public static function select(User $user, int $atelierId): ?ShopAuditor
    {
        $link = self::activeLink($user, $atelierId);
        if (! $link) {
            return null;
        }
        if ((int) $user->atelier_id !== $atelierId) {
            $user->atelier_id = $atelierId;
            $user->save();
        }

        return $link;
    }

    /**
     * هنگام ورود: اگر فقط یک فروشگاه دارد همان انتخاب می‌شود؛
     * اگر فروشگاه فعلی دیگر مجاز نیست پاک می‌شود.
     *
     * @return string|null پیام خطا اگر ورود مجاز نیست
     */
    public static function prepareLogin(User $user): ?string
    {
        $shops = self::shopsFor($user);
        if ($shops === []) {
            return 'هیچ فروشگاهی برای حسابرسی به حساب شما وصل نیست. از صاحب فروشگاه بخواهید شما را اضافه کند.';
        }

        if (count($shops) === 1) {
            self::select($user, $shops[0]['atelier_id']);

            return null;
        }

        $currentId = $user->atelier_id ? (int) $user->atelier_id : null;
        $stillAllowed = $currentId !== null
            && in_array($currentId, array_column($shops, 'atelier_id'), true);
        if (! $stillAllowed && $currentId !== null) {
            $user->atelier_id = null;
            $user->save();
        }

        return null;
    }

    /**
     * صاحب فروشگاه حسابرس را با شمارهٔ موبایل اضافه می‌کند.
     * اگر حساب حسابرس از قبل باشد (مثلاً فروشگاه دیگری اضافه‌اش کرده) فقط وصل می‌شود و رمزش دست نمی‌خورد.
     *
     * @param  array<int, string>|null  $permissions
     *
     * @throws RuntimeException
     */
    public static function link(
        int $atelierId,
        string $phone,
        string $name,
        ?string $password,
        ?array $permissions,
        ?string $note
    ): ShopAuditor {
        if (! self::tableReady()) {
            throw new RuntimeException('جدول shop_auditors وجود ندارد. migration یا SQL حسابرس را اجرا کنید.');
        }

        $user = User::where('phone', $phone)->first();
        if ($user) {
            if (! ShopStaffAccess::isAuditor($user)) {
                throw new RuntimeException('این شماره موبایل قبلاً برای صاحب فروشگاه یا کارمند ثبت شده است. برای حسابرس شمارهٔ دیگری وارد کنید.');
            }
            $exists = ShopAuditor::query()
                ->where('atelier_id', $atelierId)
                ->where('user_id', $user->id)
                ->exists();
            if ($exists) {
                throw new RuntimeException('این حسابرس قبلاً به فروشگاه شما اضافه شده است.');
            }
        } else {
            if (! is_string($password) || $password === '') {
                throw new RuntimeException('برای حسابرس جدید باید رمز ورود تعیین شود.');
            }
            $user = ShopEmployeeAccountService::createShopLoginUser(
                $name,
                $phone,
                $password,
                ShopStaffAccess::ROLE_AUDITOR,
                null
            );
            if (Schema::hasColumn('users', 'project_type')) {
                $user->project_type = ProjectType::SHOP;
                $user->save();
            }
            $shopRoleId = User::USER_TYPE_KEY['فروشگاه'];
            if (! $user->roles()->where('id', $shopRoleId)->exists()) {
                $user->roles()->attach($shopRoleId);
            }
        }

        return ShopAuditor::create([
            'atelier_id' => $atelierId,
            'user_id' => $user->id,
            'name' => $name,
            'is_active' => true,
            'permissions' => $permissions === null ? null : ShopPermissionCatalog::sanitize($permissions),
            'note' => $note,
        ]);
    }

    /**
     * اگر حسابرس الان روی این فروشگاه است، فروشگاه فعالش پاک شود.
     */
    public static function clearSelectionIfCurrent(ShopAuditor $link): void
    {
        $user = User::find($link->user_id);
        if ($user && (int) $user->atelier_id === (int) $link->atelier_id) {
            $user->atelier_id = null;
            $user->save();
        }
    }
}
