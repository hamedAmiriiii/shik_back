<?php

namespace App\Services;

use App\Models\ConsumableMaterial;
use App\Models\ConsumableMaterialLot;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProducedGoodIngredient;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ConsumableToRawMoveService
{
    /**
     * کالا و لات‌های دست‌نخورده‌اش را به انبار مواد اولیه می‌برد و از انبار مصرفی حذف می‌کند.
     *
     * @throws RuntimeException
     */
    public function move(ConsumableMaterial $consumableMaterial, ?string $name = null, ?string $unitType = null): RawMaterial
    {
        if (! Schema::hasTable('raw_materials') || ! Schema::hasTable('raw_material_lots')) {
            throw new RuntimeException('جداول مواد اولیه آماده نیست.');
        }

        $name = ConsumableMaterial::normalizeName($name ?? $consumableMaterial->name);
        if ($name === '') {
            throw new RuntimeException('نام ماده الزامی است.');
        }

        $unit = $unitType ?? $consumableMaterial->unitType();
        if (! in_array($unit, [RawMaterial::UNIT_PIECE, RawMaterial::UNIT_KG, RawMaterial::UNIT_LITER], true)) {
            $unit = RawMaterial::UNIT_PIECE;
        }
        if ($unit !== RawMaterial::UNIT_KG && ! Schema::hasColumn('raw_materials', 'unit_type')) {
            throw new RuntimeException(
                'ستون واحد مواد اولیه آماده نیست و انتقال با واحد '.$consumableMaterial->unitLabelFa().' ممکن نیست.'
            );
        }

        $moveBlocked = $this->blockReason($consumableMaterial);
        if ($moveBlocked) {
            throw new RuntimeException($moveBlocked);
        }

        $lots = $consumableMaterial->lots()->get();
        $invoiceIds = $lots->pluck('invoice_id')->filter()->unique()->values();
        if ($invoiceIds->isNotEmpty() && ! Schema::hasColumn('raw_material_lots', 'invoice_id')) {
            throw new RuntimeException(
                'اتصال فاکتور به مواد اولیه آماده نیست و انتقال موجودی این کالا موجودی را دوباره می‌سازد.'
            );
        }

        return DB::transaction(function () use ($consumableMaterial, $name, $unit) {
            $lots = $consumableMaterial->lots()->lockForUpdate()->get();
            $stillBlocked = $this->blockReason($consumableMaterial);
            if ($stillBlocked) {
                throw new RuntimeException($stillBlocked);
            }

            $raw = RawMaterial::query()
                ->where('atelier_id', $consumableMaterial->atelier_id)
                ->where('name', $name)
                ->lockForUpdate()
                ->first();

            if ($raw) {
                if ($raw->unitType() !== $unit) {
                    throw new RuntimeException(
                        '«'.$name.'» در انبار مواد اولیه با واحد '.$raw->unitLabelFa().' ثبت شده است.'
                    );
                }
            } else {
                $payload = [
                    'atelier_id' => $consumableMaterial->atelier_id,
                    'name' => $name,
                    'sale_price' => 0,
                    'note' => $consumableMaterial->note,
                ];
                if (Schema::hasColumn('raw_materials', 'unit_type')) {
                    $payload['unit_type'] = $unit;
                }
                $raw = RawMaterial::create($payload);
            }

            foreach ($lots as $lot) {
                $lotPayload = [
                    'atelier_id' => $consumableMaterial->atelier_id,
                    'raw_material_id' => $raw->id,
                    'quantity_kg' => $lot->quantity,
                    'remaining_kg' => $lot->remaining,
                    'price_per_kg' => $lot->unit_cost,
                    'purchased_at' => $lot->purchased_at,
                    'note' => $lot->note,
                ];
                if (Schema::hasColumn('raw_material_lots', 'invoice_id')) {
                    $lotPayload['invoice_id'] = $lot->invoice_id;
                }
                if (Schema::hasColumn('raw_material_lots', 'invoice_item_id')) {
                    $lotPayload['invoice_item_id'] = $lot->invoice_item_id;
                }
                RawMaterialLot::create($lotPayload);
            }

            $exclusiveIds = [];
            foreach ($lots->pluck('invoice_id')->filter()->unique() as $invoiceId) {
                $others = ConsumableMaterialLot::query()
                    ->where('invoice_id', $invoiceId)
                    ->where('consumable_material_id', '!=', $consumableMaterial->id)
                    ->lockForUpdate()
                    ->exists();
                if (! $others) {
                    $exclusiveIds[] = $invoiceId;
                }
            }
            if ($exclusiveIds !== [] && Schema::hasColumn('invoices', 'stock_target')) {
                Invoice::query()
                    ->whereIn('id', $exclusiveIds)
                    ->where('atelier_id', $consumableMaterial->atelier_id)
                    ->update(['stock_target' => 'raw']);
            }

            $itemIds = $lots->pluck('invoice_item_id')->filter()->unique()->values();
            if ($itemIds->isNotEmpty()) {
                $itemUpdate = ['title' => $name];
                if (Schema::hasColumn('invoice_items', 'unit_type')) {
                    $itemUpdate['unit_type'] = $unit;
                }
                InvoiceItem::query()->whereIn('id', $itemIds->all())->update($itemUpdate);
            }

            $consumableMaterial->lots()->delete();
            $consumableMaterial->delete();

            return $raw;
        });
    }

    public function blockReason(ConsumableMaterial $material): ?string
    {
        $name = $material->name;
        if ($material->withdrawals()->exists()) {
            return '«'.$name.'» برداشت دارد و تا وقتی برداشت دارد به انبار مواد اولیه منتقل نمی‌شود.';
        }
        if (Schema::hasColumn('produced_good_ingredients', 'consumable_material_id')
            && ProducedGoodIngredient::query()->where('consumable_material_id', $material->id)->exists()) {
            return '«'.$name.'» در فرمول کالای تولیدی استفاده شده و به انبار مواد اولیه منتقل نمی‌شود.';
        }
        foreach ($material->lots()->get() as $lot) {
            if (! $lot->isUntouched()) {
                return '«'.$name.'» بخشی از موجودی‌اش مصرف شده و به انبار مواد اولیه منتقل نمی‌شود.';
            }
        }

        return null;
    }
}
