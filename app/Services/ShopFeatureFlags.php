<?php

namespace App\Services;

use App\Models\Setting;

class ShopFeatureFlags
{
    public const RESTAURANT_CAFE = 'restaurant_cafe_enabled';

    public const ROOM_SERVICES = 'room_services_enabled';

    public const PRODUCED_GOODS = 'produced_goods_enabled';

    public const ACCOUNTING = 'accounting_enabled';

    public const KEYS = [
        self::RESTAURANT_CAFE,
        self::ROOM_SERVICES,
        self::PRODUCED_GOODS,
        self::ACCOUNTING,
    ];

    public const ALIASES = [
        'restaurant_cafe' => self::RESTAURANT_CAFE,
        'room_services' => self::ROOM_SERVICES,
        'produced_goods' => self::PRODUCED_GOODS,
        'accounting' => self::ACCOUNTING,
        self::RESTAURANT_CAFE => self::RESTAURANT_CAFE,
        self::ROOM_SERVICES => self::ROOM_SERVICES,
        self::PRODUCED_GOODS => self::PRODUCED_GOODS,
        self::ACCOUNTING => self::ACCOUNTING,
    ];

    public static function normalizeKey(string $feature): ?string
    {
        return self::ALIASES[$feature] ?? null;
    }

    /**
     * @return array<string, bool>
     */
    public static function forAtelier(?int $atelierId): array
    {
        $flags = [
            self::RESTAURANT_CAFE => false,
            self::ROOM_SERVICES => false,
            self::PRODUCED_GOODS => false,
            self::ACCOUNTING => false,
        ];
        if (! $atelierId) {
            return $flags;
        }

        Setting::setContextAtelierId($atelierId);
        foreach (self::KEYS as $key) {
            $flags[$key] = Setting::isEnabled($key, false);
        }

        return $flags;
    }

    /**
     * @param  array<int, int>  $atelierIds
     * @return array<int, array<string, bool>>
     */
    public static function forAteliers(array $atelierIds): array
    {
        $empty = self::forAtelier(null);
        $map = [];
        foreach ($atelierIds as $id) {
            $map[(int) $id] = $empty;
        }
        if ($atelierIds === []) {
            return $map;
        }

        $rows = Setting::query()
            ->whereIn('atelier_id', $atelierIds)
            ->whereIn('key', self::KEYS)
            ->get(['atelier_id', 'key', 'value']);

        foreach ($rows as $row) {
            $id = (int) $row->atelier_id;
            if (! isset($map[$id])) {
                $map[$id] = $empty;
            }
            $map[$id][$row->key] = self::isTruthy($row->value);
        }

        return $map;
    }

    public static function enabled(?int $atelierId, string $feature): bool
    {
        $key = self::normalizeKey($feature);
        if (! $key || ! $atelierId) {
            return false;
        }
        Setting::setContextAtelierId($atelierId);

        return Setting::isEnabled($key, false);
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
