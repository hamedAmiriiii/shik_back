<?php

namespace App\Services;

use App\Models\Atelier;
use App\Models\Category;
use App\Models\CategoryStockNotifyRequest;
use App\Models\Product;
use App\Tools\SmsTools;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CategoryStockNotifyService
{
    public const EVENT_NEW = 'new';

    public const EVENT_RESTOCK = 'restock';

    /**
     * ثبت شماره برای خبر موجود شدن / کالای جدید در یک دسته.
     */
    public static function subscribe(int $atelierId, int $categoryId, string $phone, ?string $note = null): CategoryStockNotifyRequest
    {
        $row = CategoryStockNotifyRequest::firstOrCreate(
            [
                'atelier_id' => $atelierId,
                'category_id' => $categoryId,
                'phone' => $phone,
            ],
            [
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]
        );

        if (! $row->wasRecentlyCreated && $note !== null) {
            $trimmed = trim($note);
            $row->note = $trimmed !== '' ? $trimmed : null;
            $row->save();
        }

        return $row;
    }

    /**
     * بعد از ثبت کالای جدید در دسته(های)ش به لیست خبر بده.
     */
    public static function notifyNewProduct(Product $product): int
    {
        return self::notifyProductEvent($product, self::EVENT_NEW);
    }

    /**
     * وقتی موجودی از ۰ به بیشتر می‌رود به لیست دسته خبر بده.
     */
    public static function notifyIfRestockedFromZero(Product $product, float $previousQuantity, float $newQuantity): int
    {
        if ($previousQuantity > 0 || $newQuantity <= 0) {
            return 0;
        }

        return self::notifyProductEvent($product, self::EVENT_RESTOCK);
    }

    /**
     * @param  self::EVENT_*  $event
     */
    public static function notifyProductEvent(Product $product, string $event): int
    {
        if (! Schema::hasTable('category_stock_notify_requests')) {
            return 0;
        }

        $atelierId = (int) ($product->atelier_id ?? 0);
        if ($atelierId <= 0 || ! $product->id) {
            return 0;
        }

        if (! ShopFeatureFlags::enabled($atelierId, ShopFeatureFlags::CUSTOMER_CLUB)) {
            return 0;
        }

        $categoryIds = self::productCategoryIds($product);
        if ($categoryIds === []) {
            return 0;
        }

        $requests = CategoryStockNotifyRequest::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('category_id', $categoryIds)
            ->get();

        if ($requests->isEmpty()) {
            return 0;
        }

        $product->loadMissing('categories');
        $categoryNames = Category::query()
            ->whereIn('id', $categoryIds)
            ->pluck('name', 'id');

        $brand = SmsTools::shopSmsBrand($atelierId);
        $productName = trim((string) ($product->name ?? 'کالا'));
        $viewUrl = self::productViewUrl($atelierId, (int) $product->id);

        $sent = 0;
        $phonesDone = [];

        foreach ($requests as $request) {
            $phone = (string) $request->phone;
            if ($phone === '' || isset($phonesDone[$phone])) {
                continue;
            }
            $phonesDone[$phone] = true;

            $catName = trim((string) ($categoryNames[$request->category_id] ?? ''));
            if ($catName === '') {
                $catName = 'دسته';
            }

            try {
                if ($event === self::EVENT_NEW) {
                    // هنوز پترن جدا برای «کالای جدید» ثبت نشده؛ متن آزاد (یا خط اختصاصی)
                    $text = "{$brand}\nکالای جدید «{$productName}» به دسته «{$catName}» اضافه شد.";
                    SmsTools::sendShopSms($phone, $text, null, null, 'category_stock_notify', $atelierId);
                } else {
                    SmsTools::sendCategoryRestockSms($phone, $productName, $catName, $atelierId);
                }
                $sent++;

                if ($viewUrl !== '') {
                    try {
                        SmsTools::sendCategoryProductLinkSms($phone, $viewUrl, $atelierId);
                    } catch (\Throwable $e) {
                        Log::warning('category stock notify link sms failed', [
                            'phone' => $phone,
                            'product_id' => $product->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('category stock notify sms failed', [
                    'phone' => $phone,
                    'product_id' => $product->id,
                    'category_id' => $request->category_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * @return array<int, int>
     */
    public static function productCategoryIds(Product $product): array
    {
        $ids = [];
        if ($product->relationLoaded('categories')) {
            foreach ($product->categories as $cat) {
                $id = (int) ($cat->id ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } else {
            $ids = $product->categories()->pluck('categories.id')->map(fn ($id) => (int) $id)->all();
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public static function productViewUrl(int $atelierId, int $productId): string
    {
        $code = trim((string) Atelier::where('id', $atelierId)->value('code'));
        if ($code === '' || $productId <= 0) {
            return '';
        }

        $base = rtrim((string) config('zarinpal.frontend_return_url', 'https://webinoplus.ir'), '/');
        if ($base === '') {
            $base = 'https://webinoplus.ir';
        }

        return $base.'/'.rawurlencode($code).'/product/'.$productId;
    }
}
