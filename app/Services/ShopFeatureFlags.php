<?php

namespace App\Services;

use App\Models\Setting;

class ShopFeatureFlags
{
    public const RESTAURANT_CAFE = 'restaurant_cafe_enabled';

    public const ROOM_SERVICES = 'room_services_enabled';

    public const PRODUCED_GOODS = 'produced_goods_enabled';

    public const ACCOUNTING = 'accounting_enabled';

    public const CUSTOMER_CLUB = 'customer_club_enabled';

    /** باشگاه هوشمند (RFM، کمپین، پیشنهاد اقدام) — جدا از باشگاه معمولی */
    public const SMART_CUSTOMER_CLUB = 'smart_customer_club_enabled';

    /** فروشگاه‌هایی که به‌صورت پیش‌فرض باشگاه مشتریان دارند (اگر هنوز در settings ست نشده). */
    public const CUSTOMER_CLUB_DEFAULT_ATELIER_IDS = [1, 5, 13, 17];

    public const KEYS = [
        self::RESTAURANT_CAFE,
        self::ROOM_SERVICES,
        self::PRODUCED_GOODS,
        self::ACCOUNTING,
        self::CUSTOMER_CLUB,
        self::SMART_CUSTOMER_CLUB,
    ];

    public const ALIASES = [
        'restaurant_cafe' => self::RESTAURANT_CAFE,
        'room_services' => self::ROOM_SERVICES,
        'produced_goods' => self::PRODUCED_GOODS,
        'accounting' => self::ACCOUNTING,
        'customer_club' => self::CUSTOMER_CLUB,
        'smart_customer_club' => self::SMART_CUSTOMER_CLUB,
        self::RESTAURANT_CAFE => self::RESTAURANT_CAFE,
        self::ROOM_SERVICES => self::ROOM_SERVICES,
        self::PRODUCED_GOODS => self::PRODUCED_GOODS,
        self::ACCOUNTING => self::ACCOUNTING,
        self::CUSTOMER_CLUB => self::CUSTOMER_CLUB,
        self::SMART_CUSTOMER_CLUB => self::SMART_CUSTOMER_CLUB,
    ];

    public static function normalizeKey(string $feature): ?string
    {
        return self::ALIASES[$feature] ?? null;
    }

    /**
     * @return array<string, bool>
     */
    public static function empty(): array
    {
        return [
            self::RESTAURANT_CAFE => false,
            self::ROOM_SERVICES => false,
            self::PRODUCED_GOODS => false,
            self::ACCOUNTING => false,
            self::CUSTOMER_CLUB => false,
            self::SMART_CUSTOMER_CLUB => false,
        ];
    }

    public static function customerClubDefaultForAtelier(int $atelierId): bool
    {
        return in_array($atelierId, self::CUSTOMER_CLUB_DEFAULT_ATELIER_IDS, true);
    }

    /**
     * @return array<string, bool>
     */
    public static function forAtelier(?int $atelierId): array
    {
        if (! $atelierId) {
            return self::empty();
        }

        return self::forAteliers([$atelierId])[$atelierId] ?? self::empty();
    }

    /**
     * @param  array<int, int>  $atelierIds
     * @return array<int, array<string, bool>>
     */
    public static function forAteliers(array $atelierIds): array
    {
        $map = [];
        foreach ($atelierIds as $id) {
            $aid = (int) $id;
            $flags = self::empty();
            $flags[self::CUSTOMER_CLUB] = self::customerClubDefaultForAtelier($aid);
            // فروشگاه‌های قدیمی با باشگاه پیش‌فرض، باشگاه هوشمند هم داشتند
            $flags[self::SMART_CUSTOMER_CLUB] = self::customerClubDefaultForAtelier($aid);
            $map[$aid] = $flags;
        }
        if ($atelierIds === []) {
            return $map;
        }

        $rows = Setting::query()
            ->whereIn('atelier_id', $atelierIds)
            ->whereIn('key', self::KEYS)
            ->get(['atelier_id', 'key', 'value']);

        $smartExplicit = [];
        foreach ($rows as $row) {
            $id = (int) $row->atelier_id;
            if (! isset($map[$id])) {
                $map[$id] = self::empty();
                $map[$id][self::CUSTOMER_CLUB] = self::customerClubDefaultForAtelier($id);
                $map[$id][self::SMART_CUSTOMER_CLUB] = self::customerClubDefaultForAtelier($id);
            }
            $map[$id][$row->key] = self::isTruthy($row->value);
            if ($row->key === self::SMART_CUSTOMER_CLUB) {
                $smartExplicit[$id] = true;
            }
        }

        // قبل از جدا شدن فلگ هوشمند، باشگاه معمولی همان دسترسی هوشمند را هم می‌داد
        foreach ($map as $id => $flags) {
            if (! empty($smartExplicit[$id])) {
                continue;
            }
            if ($flags[self::CUSTOMER_CLUB] ?? false) {
                $map[$id][self::SMART_CUSTOMER_CLUB] = true;
            }
        }

        return $map;
    }

    public static function enabled(?int $atelierId, string $feature): bool
    {
        $key = self::normalizeKey($feature);
        if (! $key || ! $atelierId) {
            return false;
        }

        return self::forAtelier($atelierId)[$key] ?? false;
    }

    public static function set(int $atelierId, string $feature, bool $enabled): string
    {
        $key = self::normalizeKey($feature);
        if (! $key) {
            abort(response()->json(['message' => 'نوع دسترسی نامعتبر است.'], 422));
        }
        Setting::setContextAtelierId($atelierId);
        Setting::set($key, $enabled ? '1' : '0');

        return $key;
    }

    public static function isTruthy($value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
