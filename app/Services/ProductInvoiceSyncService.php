<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Tools\PriceTools;
use App\Tools\ProductQuantityTools;
use Illuminate\Support\Collection;
use RuntimeException;

class ProductInvoiceSyncService
{
    /**
     * موجودی کالا را با اختلاف اقلام این فاکتور نسبت به نسخهٔ قبلی هماهنگ می‌کند.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  Collection<int, InvoiceItem>  $previousItems
     */
    public function syncFromInvoice(Invoice $invoice, array $rows, Collection $previousItems): void
    {
        $atelierId = (int) $invoice->atelier_id;
        $oldQty = [];
        foreach ($previousItems as $item) {
            $name = $this->normalizeName($item->title);
            if ($name === '') {
                continue;
            }
            $oldQty[$name] = round(($oldQty[$name] ?? 0) + (float) $item->quantity, 3);
        }

        $newQty = [];
        $landedNet = [];
        $sale = [];
        $barcode = [];
        foreach ($rows as $row) {
            $name = $this->normalizeName($row['title'] ?? '');
            if ($name === '') {
                continue;
            }
            $qty = round((float) ($row['quantity'] ?? 0), 3);
            $newQty[$name] = round(($newQty[$name] ?? 0) + $qty, 3);
            $gross = round((float) ($row['total'] ?? 0) + (float) ($row['vat_amount'] ?? 0), 2);
            $net = max(0, round($gross - (float) ($row['discount_amount'] ?? 0), 2));
            $landedNet[$name] = round(($landedNet[$name] ?? 0) + $net, 2);
            if (! empty($row['sale_price'])) {
                $sale[$name] = round((float) $row['sale_price'], 2);
            }
            $code = trim((string) ($row['barcode'] ?? ''));
            if ($code !== '') {
                $barcode[$name] = $code;
            }
        }

        foreach (array_unique(array_merge(array_keys($oldQty), array_keys($newQty))) as $name) {
            $delta = round(($newQty[$name] ?? 0) - ($oldQty[$name] ?? 0), 3);
            $product = Product::query()
                ->where('atelier_id', $atelierId)
                ->where('name', $name)
                ->first();

            $purchase = null;
            if (($newQty[$name] ?? 0) > 0) {
                $purchase = round(($landedNet[$name] ?? 0) / $newQty[$name], 2);
            }

            if (! $product) {
                if (($newQty[$name] ?? 0) <= 0) {
                    continue;
                }
                $salePrice = $sale[$name] ?? 0;
                if ($salePrice <= 0) {
                    throw new RuntimeException('قیمت فروش «'.$name.'» را وارد کنید.');
                }
                if (! ProductQuantityTools::isWholeNumber($newQty[$name])) {
                    throw new RuntimeException('تعداد کالای «'.$name.'» باید عدد صحیح باشد.');
                }
                $this->createProduct($atelierId, $name, $newQty[$name], $purchase ?? 0, $salePrice, $barcode[$name] ?? null);
                continue;
            }

            $unit = $product->unit_type ?: Product::UNIT_PIECE;
            $next = round((float) $product->quantity + $delta, 3);
            if ($next < -0.0001) {
                throw new RuntimeException('موجودی «'.$name.'» برای این اصلاح فاکتور کافی نیست.');
            }
            if ($unit === Product::UNIT_PIECE && ! ProductQuantityTools::isWholeNumber(max(0, $next))) {
                throw new RuntimeException('تعداد کالای «'.$name.'» باید عدد صحیح باشد.');
            }
            $product->quantity = max(0, $next);
            if ($purchase !== null) {
                $product->purchase_price = $purchase;
            }
            if (($sale[$name] ?? 0) > 0) {
                $rounded = PriceTools::roundSalePrice((float) $sale[$name], $atelierId);
                $product->sale_price = $rounded;
                if ($product->original_sale_price === null) {
                    $product->original_sale_price = $rounded;
                }
            }
            $product->save();
        }
    }

    protected function createProduct(
        int $atelierId,
        string $name,
        float $quantity,
        float $purchase,
        float $sale,
        ?string $barcode
    ): void {
        $sale = PriceTools::roundSalePrice($sale, $atelierId);
        $fields = [
            'atelier_id' => $atelierId,
            'name' => $name,
            'quantity' => $quantity,
            'purchase_price' => $purchase,
            'sale_price' => $sale,
            'original_sale_price' => $sale,
            'unit_type' => Product::UNIT_PIECE,
            'display_order' => Product::DEFAULT_DISPLAY_ORDER,
            'barcode' => $barcode !== null && $barcode !== '' ? $barcode : ('TMP'.now()->timestamp.random_int(10000, 99999)),
        ];
        $product = Product::create($fields);
        if ($barcode === null || $barcode === '') {
            $product->barcode = (string) $product->id;
            $product->save();
        }
    }

    protected function normalizeName(?string $name): string
    {
        $name = trim((string) $name);

        return preg_replace('/\s+/u', ' ', $name) ?? '';
    }
}
