<?php

namespace App\Services;

use App\Models\ConsumableMaterial;
use App\Models\ConsumableMaterialLot;
use App\Models\ConsumableWithdrawal;
use App\Tools\ProductQuantityTools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ConsumableFifoService
{
    /**
     * تخصیص FIFO بدون کم کردن موجودی.
     *
     * @return array{slices: list<array<string, mixed>>, shortage: float, total_cost: float}
     */
    public function plan(ConsumableMaterial $material, float $needQty, bool $lock = false): array
    {
        $needQty = round($needQty, 3);
        $query = ConsumableMaterialLot::query()
            ->where('consumable_material_id', $material->id)
            ->where('remaining', '>', 0)
            ->orderBy('purchased_at')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $lots = $query->get();
        $remainingNeed = $needQty;
        $slices = [];

        foreach ($lots as $lot) {
            if ($remainingNeed <= 0) {
                break;
            }

            $available = (float) $lot->remaining;
            $take = round(min($available, $remainingNeed), 3);
            if ($take <= 0) {
                continue;
            }

            $price = (float) $lot->unit_cost;
            $slices[] = [
                'lot_id' => $lot->id,
                'lot' => $lot,
                'quantity' => $take,
                'unit_cost' => $price,
                'cost' => round($take * $price, 2),
            ];
            $remainingNeed = round($remainingNeed - $take, 3);
        }

        $shortage = max($remainingNeed, 0);
        $totalCost = round(array_sum(array_column($slices, 'cost')), 2);

        return [
            'slices' => $slices,
            'shortage' => $shortage,
            'total_cost' => $totalCost,
        ];
    }

    public function attachStock(ConsumableMaterial $material): ConsumableMaterial
    {
        $lots = $material->relationLoaded('lots')
            ? $material->lots
            : ($material->relationLoaded('openLots')
                ? $material->openLots
                : $material->lots()->orderBy('purchased_at')->orderBy('id')->get());

        $open = $lots->filter(function (ConsumableMaterialLot $lot) {
            return (float) $lot->remaining > 0;
        })->values();

        $stock = round((float) $open->sum('remaining'), 3);
        $stockValue = round((float) $open->sum(function (ConsumableMaterialLot $lot) {
            return (float) $lot->remaining * (float) $lot->unit_cost;
        }), 2);
        $next = $open->first();

        $material->setAttribute('stock', $stock);
        $material->setAttribute('stock_value', $stockValue);
        $material->setAttribute('open_lots_count', $open->count());
        $material->setAttribute('next_unit_cost', $next ? (float) $next->unit_cost : null);
        $material->setAttribute('lots', $lots->map(function (ConsumableMaterialLot $lot) {
            return [
                'id' => $lot->id,
                'quantity' => (float) $lot->quantity,
                'remaining' => (float) $lot->remaining,
                'unit_cost' => (float) $lot->unit_cost,
                'purchased_at' => optional($lot->purchased_at)->toDateTimeString(),
                'invoice_id' => $lot->invoice_id,
                'invoice_item_id' => $lot->invoice_item_id,
                'note' => $lot->note,
            ];
        })->values());
        $material->makeHidden(['openLots']);

        return $material;
    }

    public function withdraw(
        ConsumableMaterial $material,
        float $quantity,
        ?string $note = null,
        $withdrawnAt = null
    ): ConsumableWithdrawal {
        $this->assertTablesReady();
        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw new RuntimeException('تعداد برداشت باید بیشتر از صفر باشد.');
        }
        if (! ProductQuantityTools::isWholeNumber($quantity)) {
            throw new RuntimeException('تعداد برداشت باید عدد صحیح باشد.');
        }

        return DB::transaction(function () use ($material, $quantity, $note, $withdrawnAt) {
            $plan = $this->plan($material, $quantity, true);
            if ($plan['shortage'] > 0) {
                throw new RuntimeException(
                    'موجودی «'.$material->name.'» کافی نیست. کمبود: '.$plan['shortage'].' عدد.'
                );
            }

            $withdrawal = ConsumableWithdrawal::create([
                'atelier_id' => $material->atelier_id,
                'consumable_material_id' => $material->id,
                'quantity' => $quantity,
                'total_cost' => 0,
                'note' => $note,
                'withdrawn_at' => $withdrawnAt ?: now(),
            ]);

            $totalCost = 0.0;
            foreach ($plan['slices'] as $slice) {
                /** @var ConsumableMaterialLot $lot */
                $lot = $slice['lot'];
                $take = $slice['quantity'];
                $left = round((float) $lot->remaining - $take, 3);
                if ($left < 0) {
                    throw new RuntimeException('موجودی لات همزمان تغییر کرده است. دوباره تلاش کنید.');
                }
                $lot->remaining = $left;
                $lot->save();

                $withdrawal->slices()->create([
                    'consumable_material_lot_id' => $lot->id,
                    'quantity' => $take,
                    'unit_cost' => $slice['unit_cost'],
                    'cost' => $slice['cost'],
                ]);
                $totalCost += $slice['cost'];
            }

            $withdrawal->total_cost = round($totalCost, 2);
            $withdrawal->save();

            return $withdrawal->load(['slices.lot', 'material']);
        });
    }

    public function assertTablesReady(): void
    {
        if (
            ! Schema::hasTable('consumable_materials')
            || ! Schema::hasTable('consumable_material_lots')
            || ! Schema::hasTable('consumable_withdrawals')
            || ! Schema::hasTable('consumable_withdrawal_slices')
        ) {
            throw new RuntimeException(
                'جداول مواد مصرفی آماده نیست. database/sql/create_consumable_materials_manual.sql را اجرا کنید.'
            );
        }
    }
}
