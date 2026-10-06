<?php

namespace App\Services;

use App\Models\Setting;

/**
 * پکیج‌های فروش پنل از لندینگ (پایه / فروش کامل / نسخه ۲۱).
 * شناسهٔ عددی برای gateway_payments.item_id ثابت است.
 * قیمت از تنظیمات سراسری قابل ویرایش است؛ آیتم‌ها ثابت‌اند.
 */
class ShopPackageCatalog
{
    public const SLUG_BASE = 'base';

    public const SLUG_FULL_SALE = 'full_sale';

    public const SLUG_V21 = 'v21';

    public const PRICE_KEY_PREFIX = 'shop_package_price_';

    public const PRICE_KEY_SUFFIX = '_toman';

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'id' => 1,
                'slug' => self::SLUG_BASE,
                'name' => 'پایه',
                'tag' => null,
                'description' => 'منوی آنلاین و صندوق فروش برای شروع',
                'price_toman' => 11_000_000,
                'price_rial' => 110_000_000,
                'duration_days' => 365,
                'popular' => false,
                'features' => [
                    'منوی دیجیتال با QR میز',
                    'چندین تم نمایش منو',
                    'فروش نقد، کارت و ترکیبی',
                    'کالا، موجودی و گزارش فروش',
                    'چاپ فیش و نصب روی موبایل',
                ],
                'feature_flags' => [
                    ShopFeatureFlags::RESTAURANT_CAFE => true,
                    ShopFeatureFlags::ROOM_SERVICES => false,
                    ShopFeatureFlags::PRODUCED_GOODS => false,
                    ShopFeatureFlags::ACCOUNTING => false,
                    ShopFeatureFlags::CUSTOMER_CLUB => false,
                ],
            ],
            [
                'id' => 2,
                'slug' => self::SLUG_FULL_SALE,
                'name' => 'فروش کامل',
                'tag' => null,
                'description' => 'منو به‌همراه پنل فروش کامل رستوران',
                'price_toman' => 16_000_000,
                'price_rial' => 160_000_000,
                'duration_days' => 365,
                'popular' => true,
                'features' => [
                    'همه امکانات پایه',
                    'سفارش آنلاین میز و اتاق',
                    'نسیه، خرید و سود',
                    'پرداخت آنلاین روی منو',
                    'پیجر گارسون و حقوق پرسنل',
                ],
                'feature_flags' => [
                    ShopFeatureFlags::RESTAURANT_CAFE => true,
                    ShopFeatureFlags::ROOM_SERVICES => true,
                    ShopFeatureFlags::PRODUCED_GOODS => true,
                    ShopFeatureFlags::ACCOUNTING => false,
                    ShopFeatureFlags::CUSTOMER_CLUB => false,
                ],
            ],
            [
                'id' => 3,
                'slug' => self::SLUG_V21,
                'name' => 'نسخه ۲۱',
                'tag' => 'پنل فروش + باشگاه هوشمند',
                'description' => 'منو، پنل فروش و باشگاه مشتریان هوشمند',
                'price_toman' => 29_000_000,
                'price_rial' => 290_000_000,
                'duration_days' => 365,
                'popular' => false,
                'features' => [
                    'همه امکانات فروش کامل',
                    'باشگاه مشتریان و اعتبار خرید',
                    'گروه‌بندی هوشمند و کمپین',
                    'پیامک هدفمند و گزارش اثر',
                    'دفتر حسابداری و بستن سال',
                ],
                'feature_flags' => [
                    ShopFeatureFlags::RESTAURANT_CAFE => true,
                    ShopFeatureFlags::ROOM_SERVICES => true,
                    ShopFeatureFlags::PRODUCED_GOODS => true,
                    ShopFeatureFlags::ACCOUNTING => true,
                    ShopFeatureFlags::CUSTOMER_CLUB => true,
                ],
            ],
        ];
    }

    public static function priceSettingKey(string $slug): string
    {
        return self::PRICE_KEY_PREFIX.$slug.self::PRICE_KEY_SUFFIX;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::withPriceOverrides(self::defaults());
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return list<array<string, mixed>>
     */
    public static function withPriceOverrides(array $packages): array
    {
        $prev = Setting::contextAtelierId();
        Setting::setContextAtelierId(null);
        try {
            foreach ($packages as &$pkg) {
                $slug = (string) ($pkg['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $raw = Setting::get(self::priceSettingKey($slug));
                if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                    continue;
                }
                $toman = max(0, (int) $raw);
                $pkg['price_toman'] = $toman;
                $pkg['price_rial'] = $toman * 10;
                $pkg['price_overridden'] = true;
            }
            unset($pkg);
        } finally {
            Setting::setContextAtelierId($prev);
        }

        return $packages;
    }

    public static function setPriceToman(string $slug, int $priceToman): void
    {
        $pkg = null;
        foreach (self::defaults() as $row) {
            if ($row['slug'] === $slug) {
                $pkg = $row;
                break;
            }
        }
        if (! $pkg) {
            throw new \InvalidArgumentException('پکیج یافت نشد.');
        }

        $toman = max(0, $priceToman);
        $prev = Setting::contextAtelierId();
        Setting::setContextAtelierId(null);
        try {
            Setting::set(self::priceSettingKey($slug), (string) $toman);
        } finally {
            Setting::setContextAtelierId($prev);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        foreach (self::all() as $pkg) {
            if ((int) $pkg['id'] === $id) {
                return $pkg;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        foreach (self::all() as $pkg) {
            if ($pkg['slug'] === $slug) {
                return $pkg;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function publicList(): array
    {
        return array_map(static function (array $pkg) {
            return [
                'id' => $pkg['id'],
                'slug' => $pkg['slug'],
                'name' => $pkg['name'],
                'tag' => $pkg['tag'],
                'description' => $pkg['description'],
                'price_toman' => $pkg['price_toman'],
                'price_rial' => $pkg['price_rial'],
                'duration_days' => $pkg['duration_days'],
                'popular' => $pkg['popular'],
                'features' => $pkg['features'],
            ];
        }, self::all());
    }

    /**
     * لیست ادمین (بدون feature_flags؛ با فلگ override قیمت).
     *
     * @return list<array<string, mixed>>
     */
    public static function adminList(): array
    {
        return array_map(static function (array $pkg) {
            return [
                'id' => $pkg['id'],
                'slug' => $pkg['slug'],
                'name' => $pkg['name'],
                'tag' => $pkg['tag'],
                'description' => $pkg['description'],
                'price_toman' => (int) $pkg['price_toman'],
                'price_rial' => (int) $pkg['price_rial'],
                'duration_days' => (int) $pkg['duration_days'],
                'popular' => (bool) $pkg['popular'],
                'price_overridden' => (bool) ($pkg['price_overridden'] ?? false),
            ];
        }, self::all());
    }
}
