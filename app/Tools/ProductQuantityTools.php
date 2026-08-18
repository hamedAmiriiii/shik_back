<?php

namespace App\Tools;

use App\Models\Product;

class ProductQuantityTools
{
    public const DECIMALS = 3;

    public const MIN_KG = 0.001;

    public const MIN_PIECE = 1;

    public static function isWeightUnit(?string $unitType): bool
    {
        return ($unitType ?? Product::UNIT_PIECE) === Product::UNIT_KG;
    }

    public static function unitLabel(?string $unitType): string
    {
        return self::isWeightUnit($unitType) ? 'کیلو' : 'عدد';
    }

    public static function priceUnitLabel(?string $unitType): string
    {
        return self::isWeightUnit($unitType) ? 'هر کیلو' : 'هر عدد';
    }

    public static function minQuantity(?string $unitType): float
    {
        return self::isWeightUnit($unitType) ? self::MIN_KG : (float) self::MIN_PIECE;
    }

    public static function normalize(float|string|null $quantity, ?string $unitType): float
    {
        $value = round((float) $quantity, self::DECIMALS);

        if (! self::isWeightUnit($unitType)) {
            $value = (float) (int) round($value);
        }

        return $value;
    }

    public static function hasSufficientStock(float|string|null $available, float|string|null $requested): bool
    {
        return self::normalize($available, Product::UNIT_KG)
            >= self::normalize($requested, Product::UNIT_KG);
    }

    public static function isWholeNumber(float|string|null $quantity): bool
    {
        $value = (float) $quantity;

        return abs($value - round($value)) < 0.0001;
    }

    public static function validateProductStockQuantity(float|string|null $quantity, ?string $unitType): ?string
    {
        $normalized = self::normalize($quantity, $unitType);

        if ($normalized < 0) {
            return 'موجودی نمی‌تواند منفی باشد.';
        }

        if (self::isWeightUnit($unitType)) {
            return null;
        }

        if (! self::isWholeNumber($quantity)) {
            return 'موجودی محصولات تعدادی باید عدد صحیح باشد.';
        }

        return null;
    }

    public static function validateSaleQuantity(float|string|null $quantity, ?string $unitType): ?string
    {
        $normalized = self::normalize($quantity, $unitType);
        $min = self::minQuantity($unitType);
        $unitLabel = self::unitLabel($unitType);

        if ($normalized < $min) {
            return "حداقل مقدار فروش {$min} {$unitLabel} است.";
        }

        if (! self::isWeightUnit($unitType) && ! self::isWholeNumber($quantity)) {
            return 'تعداد فروش محصولات تعدادی باید عدد صحیح باشد.';
        }

        return null;
    }

    public static function validateReturnQuantity(
        float|string|null $returnQuantity,
        float|string|null $lineQuantity,
        ?string $unitType
    ): ?string {
        $returnQty = self::normalize($returnQuantity, $unitType);
        $lineQty = self::normalize($lineQuantity, $unitType);
        $min = self::minQuantity($unitType);
        $unitLabel = self::unitLabel($unitType);

        if ($returnQty < $min || $returnQty > $lineQty) {
            return "مقدار برگشت باید بین {$min} و {$lineQty} {$unitLabel} باشد.";
        }

        if (! self::isWeightUnit($unitType) && ! self::isWholeNumber($returnQuantity)) {
            return 'تعداد برگشت محصولات تعدادی باید عدد صحیح باشد.';
        }

        return null;
    }

    public static function isFullReturn(float|string|null $returnQuantity, float|string|null $lineQuantity): bool
    {
        $returnQty = self::normalize($returnQuantity, Product::UNIT_KG);
        $lineQty = self::normalize($lineQuantity, Product::UNIT_KG);

        return $returnQty >= $lineQty;
    }
}
