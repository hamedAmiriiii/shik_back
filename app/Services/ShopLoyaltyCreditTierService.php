<?php

namespace App\Services;

use App\Models\ShopLoyaltyCreditTier;
use App\Tools\PriceTools;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopLoyaltyCreditTierService
{
    /** @var array<int, array{max_amount: float|null, percent: float}> */
    public const DEFAULT_TIERS = [
        ['max_amount' => 1000000, 'percent' => 3],
        ['max_amount' => 2000000, 'percent' => 4],
        ['max_amount' => null, 'percent' => 5],
    ];

    public static function tableReady(): bool
    {
        return Schema::hasTable('shop_loyalty_credit_tiers');
    }

    /**
     * @return Collection<int, ShopLoyaltyCreditTier>
     */
    public static function getTiersForAtelier(?int $atelierId): Collection
    {
        if ($atelierId === null || ! self::tableReady()) {
            return self::defaultTierModels();
        }

        self::ensureDefaultsForAtelier($atelierId);

        return ShopLoyaltyCreditTier::query()
            ->where('atelier_id', $atelierId)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function tiersForApi(?int $atelierId): array
    {
        return self::getTiersForAtelier($atelierId)
            ->map(function (ShopLoyaltyCreditTier $tier) {
                return [
                    'sort_order' => (int) $tier->sort_order,
                    'max_amount' => $tier->max_amount !== null ? (float) $tier->max_amount : null,
                    'percent' => (float) $tier->percent,
                ];
            })
            ->values()
            ->all();
    }

    public static function calculateCredit(float $purchaseAmount, ?int $atelierId = null): float
    {
        if ($purchaseAmount <= 0) {
            return 0.0;
        }

        $tiers = self::getTiersForAtelier($atelierId);

        foreach ($tiers as $tier) {
            if ($tier->max_amount === null || $purchaseAmount <= (float) $tier->max_amount) {
                $credit = $purchaseAmount * ((float) $tier->percent / 100);

                return PriceTools::roundToThousand((float) $credit);
            }
        }

        $last = $tiers->last();
        if ($last) {
            $credit = $purchaseAmount * ((float) $last->percent / 100);

            return PriceTools::roundToThousand((float) $credit);
        }

        return 0.0;
    }

    public static function ensureDefaultsForAtelier(int $atelierId): void
    {
        if ($atelierId <= 0 || ! self::tableReady()) {
            return;
        }

        $exists = ShopLoyaltyCreditTier::query()
            ->where('atelier_id', $atelierId)
            ->exists();

        if ($exists) {
            return;
        }

        foreach (self::DEFAULT_TIERS as $index => $tier) {
            ShopLoyaltyCreditTier::create([
                'atelier_id' => $atelierId,
                'sort_order' => $index + 1,
                'max_amount' => $tier['max_amount'],
                'percent' => $tier['percent'],
            ]);
        }
    }

    /**
     * @param  array<int, array{max_amount?: float|int|string|null, percent: float|int|string}>  $tiers
     * @return Collection<int, ShopLoyaltyCreditTier>
     */
    public static function syncTiers(int $atelierId, array $tiers): Collection
    {
        if (! self::tableReady()) {
            throw new \RuntimeException('جدول shop_loyalty_credit_tiers وجود ندارد.');
        }

        $normalized = self::validateAndNormalizeTiers($tiers);

        return DB::transaction(function () use ($atelierId, $normalized) {
            ShopLoyaltyCreditTier::query()
                ->where('atelier_id', $atelierId)
                ->delete();

            foreach ($normalized as $index => $tier) {
                ShopLoyaltyCreditTier::create([
                    'atelier_id' => $atelierId,
                    'sort_order' => $index + 1,
                    'max_amount' => $tier['max_amount'],
                    'percent' => $tier['percent'],
                ]);
            }

            return ShopLoyaltyCreditTier::query()
                ->where('atelier_id', $atelierId)
                ->orderBy('sort_order')
                ->get();
        });
    }

    /**
     * @param  array<int, array{max_amount?: float|int|string|null, percent: float|int|string}>  $tiers
     * @return array<int, array{max_amount: float|null, percent: float}>
     */
    protected static function validateAndNormalizeTiers(array $tiers): array
    {
        if (count($tiers) < 1) {
            throw new \InvalidArgumentException('حداقل یک بازه اعتبار لازم است.');
        }

        if (count($tiers) > ShopLoyaltyCreditTier::MAX_TIERS_PER_SHOP) {
            throw new \InvalidArgumentException('حداکثر '.ShopLoyaltyCreditTier::MAX_TIERS_PER_SHOP.' بازه مجاز است.');
        }

        $normalized = [];
        $previousMax = null;

        foreach ($tiers as $index => $tier) {
            if (! isset($tier['percent']) || ! is_numeric($tier['percent'])) {
                throw new \InvalidArgumentException('درصد هر بازه الزامی است.');
            }

            $percent = round((float) $tier['percent'], 2);
            if ($percent < 0 || $percent > 100) {
                throw new \InvalidArgumentException('درصد باید بین ۰ تا ۱۰۰ باشد.');
            }

            $maxAmount = array_key_exists('max_amount', $tier) && $tier['max_amount'] !== null && $tier['max_amount'] !== ''
                ? round((float) $tier['max_amount'], 2)
                : null;

            $isLast = $index === count($tiers) - 1;

            if (! $isLast) {
                if ($maxAmount === null || $maxAmount <= 0) {
                    throw new \InvalidArgumentException('سقف مبلغ برای بازه‌های میانی الزامی است.');
                }
            }

            if ($maxAmount !== null && $previousMax !== null && $maxAmount <= $previousMax) {
                throw new \InvalidArgumentException('سقف هر بازه باید بیشتر از بازهٔ قبلی باشد.');
            }

            $normalized[] = [
                'max_amount' => $maxAmount,
                'percent' => $percent,
            ];

            if ($maxAmount !== null) {
                $previousMax = $maxAmount;
            }
        }

        return $normalized;
    }

    /**
     * @return Collection<int, ShopLoyaltyCreditTier>
     */
    protected static function defaultTierModels(): Collection
    {
        return collect(self::DEFAULT_TIERS)->map(function (array $tier, int $index) {
            $model = new ShopLoyaltyCreditTier([
                'sort_order' => $index + 1,
                'max_amount' => $tier['max_amount'],
                'percent' => $tier['percent'],
            ]);

            return $model;
        });
    }
}
