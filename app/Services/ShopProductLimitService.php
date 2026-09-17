<?php

namespace App\Services;

use App\Models\Atelier;
use App\Models\Product;
use App\Support\ProjectType;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ShopProductLimitService
{
    /** سقف پیش‌فرض تعداد کالا برای هر فروشگاه (بدون اشتراک طلایی) */
    public const DEFAULT_LIMIT = 1000;

    public const ERROR_CODE = 'PRODUCT_LIMIT_REACHED';

    public static function atelierHasUnlimitedProducts(?Atelier $atelier): bool
    {
        if (! $atelier) {
            return false;
        }
        if (! Schema::hasColumn('ateliers', 'unlimited_products')) {
            return false;
        }

        return (bool) $atelier->unlimited_products;
    }

    public static function productCount(int $atelierId): int
    {
        return (int) Product::query()->where('atelier_id', $atelierId)->count();
    }

    public static function upgradePathForAtelier(?Atelier $atelier): string
    {
        if ($atelier && $atelier->projectType() === ProjectType::OIL) {
            return '/oil/plans';
        }

        return '/admin/shop-plans';
    }

    /**
     * @return array{message: string, code: string, limit: int, current: int, remaining: int, upgrade_url: string, upgrade_label: string}
     */
    public static function limitPayload(int $atelierId, int $current, ?Atelier $atelier = null): array
    {
        $atelier = $atelier ?? Atelier::query()->find($atelierId);

        return [
            'message' => 'شما به سقف ایجاد محصول رسیدید. برای ثبت بیشتر باید اشتراک طلایی بخرید.',
            'code' => self::ERROR_CODE,
            'limit' => self::DEFAULT_LIMIT,
            'current' => $current,
            'remaining' => max(0, self::DEFAULT_LIMIT - $current),
            'upgrade_url' => self::upgradePathForAtelier($atelier),
            'upgrade_label' => 'اشتراک طلایی',
        ];
    }

    /**
     * قبل از ثبت $additionalCount کالای جدید؛ در صورت رد شدن RuntimeException با payload در getPayload().
     */
    public static function assertCanCreate(int $atelierId, int $additionalCount = 1): void
    {
        if ($additionalCount <= 0) {
            return;
        }

        $atelier = Atelier::query()->find($atelierId);
        if (self::atelierHasUnlimitedProducts($atelier)) {
            return;
        }

        $current = self::productCount($atelierId);
        if ($current + $additionalCount <= self::DEFAULT_LIMIT) {
            return;
        }

        $payload = self::limitPayload($atelierId, $current, $atelier);
        throw new ProductLimitReachedException($payload['message'], $payload);
    }
}
