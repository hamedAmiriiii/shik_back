<?php

namespace App\Services;

use App\Models\ConsumableMaterial;
use App\Models\ConsumableMaterialLot;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Tools\ProductQuantityTools;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class ConsumableInvoiceSyncService
{
    public function syncFromInvoice(Invoice $invoice): void
    {
        if (
            ! Schema::hasTable('consumable_materials')
            || ! Schema::hasTable('consumable_material_lots')
        ) {
            throw new RuntimeException(
                'جداول مواد مصرفی آماده نیست. database/sql/create_consumable_materials_manual.sql را اجرا کنید.'
            );
        }

        $invoice->loadMissing('items');
        $atelierId = (int) $invoice->atelier_id;

        $existingLots = ConsumableMaterialLot::query()
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->get();

        foreach ($existingLots as $lot) {
            if (! $lot->isUntouched()) {
                throw new RuntimeException(
                    'برخی اقلام مواد مصرفی این فاکتور قبلاً برداشت شده‌اند و قابل ویرایش نیستند.'
                );
            }
        }

        foreach ($existingLots as $lot) {
            $lot->delete();
        }

        $purchasedAt = $this->resolvePurchasedAt($invoice);
        $prepared = [];

        foreach ($invoice->items as $item) {
            /** @var InvoiceItem $item */
            $name = ConsumableMaterial::normalizeName($item->title);
            $qty = round((float) $item->quantity, 3);
            $unitPrice = round((float) $item->unit_price, 2);

            if ($name === '' || $qty <= 0 || $unitPrice < 0) {
                continue;
            }
            $unit = $this->unitOf($item);
            if ($unit === ConsumableMaterial::UNIT_PIECE && ! ProductQuantityTools::isWholeNumber($qty)) {
                throw new RuntimeException('تعداد مواد مصرفی «'.$name.'» باید عدد صحیح باشد.');
            }

            $base = round($qty * $unitPrice, 2);
            $vat = round((float) ($item->vat_amount ?? 0), 2);
            $prepared[] = [
                'item' => $item,
                'name' => $name,
                'qty' => $qty,
                'unit' => $unit,
                'gross' => round($base + $vat, 2),
                'line_discount' => round((float) ($item->discount_amount ?? 0), 2),
            ];
        }

        $lineDiscountSum = round(array_sum(array_map(static function (array $row) {
            return (float) $row['line_discount'];
        }, $prepared)), 2);
        $discount = $lineDiscountSum > 0
            ? 0.0
            : (Schema::hasColumn('invoices', 'discount_amount')
                ? round((float) ($invoice->discount_amount ?? 0), 2)
                : 0.0);
        $shares = $lineDiscountSum > 0
            ? array_map(static function (array $row) {
                return (float) $row['line_discount'];
            }, $prepared)
            : $this->allocateDiscount($prepared, $discount);

        foreach ($prepared as $index => $row) {
            $material = ConsumableMaterial::query()
                ->where('atelier_id', $atelierId)
                ->where('name', $row['name'])
                ->first();

            $unit = $row['unit'] ?? ConsumableMaterial::UNIT_PIECE;
            if (! $material) {
                $create = [
                    'atelier_id' => $atelierId,
                    'name' => $row['name'],
                ];
                if (Schema::hasColumn('consumable_materials', 'unit_type')) {
                    $create['unit_type'] = $unit;
                }
                $material = ConsumableMaterial::create($create);
            } elseif ($material->unitType() !== $unit) {
                throw new RuntimeException(
                    '«'.$row['name'].'» در انبار مصرفی با واحد '.$material->unitLabelFa().' ثبت شده است.'
                );
            }

            $share = $shares[$index] ?? 0.0;
            $net = max(0, round($row['gross'] - $share, 2));
            $unitCost = $row['qty'] > 0 ? round($net / $row['qty'], 2) : 0.0;

            ConsumableMaterialLot::create([
                'atelier_id' => $atelierId,
                'consumable_material_id' => $material->id,
                'quantity' => $row['qty'],
                'remaining' => $row['qty'],
                'unit_cost' => $unitCost,
                'purchased_at' => $purchasedAt,
                'invoice_id' => $invoice->id,
                'invoice_item_id' => $row['item']->id,
                'note' => null,
            ]);
        }
    }

    /**
     * تخفیف کلی به نسبت مبلغ هر ردیف (فی × تعداد + ارزش افزوده همان ردیف) تقسیم می‌شود.
     *
     * @param  list<array{gross: float}>  $rows
     * @return list<float>
     */
    protected function allocateDiscount(array $rows, float $discount): array
    {
        $shares = array_fill(0, count($rows), 0.0);
        if ($discount <= 0 || $rows === []) {
            return $shares;
        }

        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) $row['gross'];
        }
        if ($sum <= 0) {
            return $shares;
        }

        $allocated = 0.0;
        $last = count($rows) - 1;
        foreach ($rows as $index => $row) {
            if ($index === $last) {
                $shares[$index] = round($discount - $allocated, 2);
                break;
            }
            $share = round($discount * ((float) $row['gross'] / $sum), 2);
            $shares[$index] = $share;
            $allocated = round($allocated + $share, 2);
        }

        return $shares;
    }

    protected function unitOf(InvoiceItem $item): string
    {
        $value = Schema::hasColumn('invoice_items', 'unit_type')
            ? (string) ($item->unit_type ?? '')
            : '';
        if ($value === ConsumableMaterial::UNIT_KG || $value === ConsumableMaterial::UNIT_LITER) {
            return $value;
        }

        return ConsumableMaterial::UNIT_PIECE;
    }

    protected function resolvePurchasedAt(Invoice $invoice): Carbon
    {
        $raw = $invoice->getRawOriginal('date');
        if (! $raw) {
            $attributes = $invoice->getAttributes();
            $raw = $attributes['date'] ?? null;
        }

        if ($raw instanceof Carbon) {
            $raw = $raw->format('Y-m-d');
        }

        if (is_string($raw) && trim($raw) !== '') {
            try {
                $parsed = Carbon::parse($raw);
                if ($parsed->year < 1700) {
                    return (new Jalalian($parsed->year, $parsed->month, $parsed->day))->toCarbon()->startOfDay();
                }

                return $parsed->startOfDay();
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return now();
    }
}
