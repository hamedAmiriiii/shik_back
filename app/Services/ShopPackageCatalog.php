<?php

namespace App\Services;

/**
 * پکیج‌های فروش پنل از لندینگ (پایه / فروش کامل / نسخه ۲۱).
 * شناسهٔ عددی برای gateway_payments.item_id ثابت است.
 */
class ShopPackageCatalog
{
    public const SLUG_BASE = 'base';

    public const SLUG_FULL_SALE = 'full_sale';

    public const SLUG_V21 = 'v21';

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
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
}
