<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStockNotifyRequest;
use App\Tools\SmsTools;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProductStockNotifyService
{
    /**
     * ثبت درخواست اطلاع‌رسانی موجود شدن کالا.
     */
    public static function subscribe(int $atelierId, int $productId, string $phone): ProductStockNotifyRequest
    {
        return ProductStockNotifyRequest::firstOrCreate([
            'atelier_id' => $atelierId,
            'product_id' => $productId,
            'phone' => $phone,
        ]);
    }

    /**
     * وقتی موجودی نسبت به قبل زیاد شد، به متقاضیان پیامک بزن و از لیست پاک کن.
     */
    public static function notifyIfRestocked(Product $product, float $previousQuantity, float $newQuantity): int
    {
        if (! Schema::hasTable('product_stock_notify_requests')) {
            return 0;
        }

        if ($newQuantity <= $previousQuantity) {
            return 0;
        }

        $atelierId = (int) ($product->atelier_id ?? 0);
        if ($atelierId <= 0 || ! $product->id) {
            return 0;
        }

        $requests = ProductStockNotifyRequest::query()
            ->where('atelier_id', $atelierId)
            ->where('product_id', $product->id)
            ->get();

        if ($requests->isEmpty()) {
            return 0;
        }

        $brand = SmsTools::shopSmsBrand($atelierId);
        $productName = trim((string) ($product->name ?? 'کالا'));
        $message = "{$brand}\nکالای «{$productName}» موجود شد.";

        $sent = 0;
        foreach ($requests as $request) {
            try {
                SmsTools::sendShopSms(
                    $request->phone,
                    $message,
                    null,
                    null,
                    'stock_notify',
                    $atelierId
                );
                $sent++;
            } catch (\Throwable $e) {
                    Log::warning('stock notify sms failed', [
                    'phone' => $request->phone,
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $request->delete();
        }

        return $sent;
    }

    /**
     * بعد از افزایش موجودی با increment، متقاضیان را مطلع کن.
     */
    public static function afterQuantityIncrease(Product $product, float $addedQuantity): int
    {
        if ($addedQuantity <= 0) {
            return 0;
        }

        $fresh = $product->fresh() ?? $product;
        $newQuantity = (float) ($fresh->quantity ?? 0);
        $previousQuantity = $newQuantity - $addedQuantity;

        return self::notifyIfRestocked($fresh, $previousQuantity, $newQuantity);
    }
}
