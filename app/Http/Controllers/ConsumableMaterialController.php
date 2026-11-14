<?php

namespace App\Http\Controllers;

use App\Models\ConsumableMaterial;
use App\Models\ConsumableMaterialLot;
use App\Models\ConsumableWithdrawal;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProducedGoodIngredient;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use App\Services\ConsumableFifoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ConsumableMaterialController extends Controller
{
    public function index(Request $request, ConsumableFifoService $fifo)
    {
        if (! Schema::hasTable('consumable_materials')) {
            return response()->json([
                'message' => 'جداول مواد مصرفی آماده نیست. database/sql/create_consumable_materials_manual.sql را اجرا کنید.',
                'data' => [],
            ], 200);
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);

        $lotRel = $request->boolean('with_all_lots') ? 'lots' : 'openLots';
        $query = ConsumableMaterial::query()
            ->where('atelier_id', $atelierId)
            ->with($lotRel)
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            if ($search !== '') {
                $query->where('name', 'like', '%'.$search.'%');
            }
        }

        $attach = function ($materials) use ($fifo) {
            return $materials->each(function (ConsumableMaterial $material) use ($fifo) {
                $fifo->attachStock($material);
            });
        };

        if ($request->boolean('paginate')) {
            $perPage = (int) $request->input('per_page', 30);
            $perPage = $perPage > 0 ? min($perPage, 100) : 30;
            $page = $query->paginate($perPage);
            $attach($page->getCollection());

            return response($page, 200);
        }

        $limit = (int) $request->input('limit', 0);
        if ($limit > 0) {
            $query->limit(min($limit, 50));
        }

        return response($attach($query->get()), 200);
    }

    public function store(Request $request, ConsumableFifoService $fifo)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json(['message' => 'دسترسی ندارید.'], 403);
        }

        try {
            $fifo->assertTablesReady();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $fields = $request->validate([
            'name' => 'required|string|max:255',
            'note' => 'nullable|string',
        ]);

        $name = ConsumableMaterial::normalizeName($fields['name']);
        if ($name === '') {
            return response()->json(['message' => 'نام ماده مصرفی الزامی است.'], 422);
        }

        $exists = ConsumableMaterial::query()
            ->where('atelier_id', $atelierId)
            ->where('name', $name)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'این ماده مصرفی از قبل ثبت شده است.'], 422);
        }

        $material = ConsumableMaterial::create([
            'atelier_id' => $atelierId,
            'name' => $name,
            'note' => $fields['note'] ?? null,
        ]);

        return response($fifo->attachStock($material->load('lots')), 201);
    }

    public function show(Request $request, ConsumableMaterial $consumableMaterial, ConsumableFifoService $fifo)
    {
        $this->assertModelBelongsToStaffAtelier($request, $consumableMaterial);
        $consumableMaterial->load(['lots', 'withdrawals' => function ($q) {
            $q->with('slices')->limit(50);
        }]);

        return response($fifo->attachStock($consumableMaterial), 200);
    }

    public function update(Request $request, ConsumableMaterial $consumableMaterial, ConsumableFifoService $fifo)
    {
        $this->assertModelBelongsToStaffAtelier($request, $consumableMaterial);

        $fields = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'note' => 'nullable|string',
            'unit_type' => 'sometimes|in:piece,kg,liter',
        ]);

        if (array_key_exists('unit_type', $fields)) {
            if (! Schema::hasColumn('consumable_materials', 'unit_type')) {
                return response()->json([
                    'message' => 'ستون واحد مواد مصرفی آماده نیست. database/sql/add_invoice_stock_target_and_units_manual.sql را اجرا کنید.',
                ], 422);
            }
            if ($fields['unit_type'] !== $consumableMaterial->unitType()) {
                $blocked = $consumableMaterial->unitChangeBlockReason();
                if ($blocked) {
                    return response()->json(['message' => $blocked], 422);
                }
            }
        }

        if (array_key_exists('name', $fields)) {
            $name = ConsumableMaterial::normalizeName($fields['name']);
            if ($name === '') {
                return response()->json(['message' => 'نام ماده مصرفی الزامی است.'], 422);
            }
            $exists = ConsumableMaterial::query()
                ->where('atelier_id', $consumableMaterial->atelier_id)
                ->where('name', $name)
                ->where('id', '!=', $consumableMaterial->id)
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'این نام قبلاً برای ماده دیگری ثبت شده است.'], 422);
            }
            $fields['name'] = $name;
        }

        $consumableMaterial->update($fields);

        return response($fifo->attachStock($consumableMaterial->fresh()->load('lots')), 200);
    }

    /**
     * کالا و لات‌های دست‌نخورده‌اش را به انبار مواد اولیه می‌برد و از انبار مصرفی حذف می‌کند.
     */
    public function moveToRaw(Request $request, ConsumableMaterial $consumableMaterial)
    {
        $this->assertModelBelongsToStaffAtelier($request, $consumableMaterial);

        if (! Schema::hasTable('raw_materials') || ! Schema::hasTable('raw_material_lots')) {
            return response()->json(['message' => 'جداول مواد اولیه آماده نیست.'], 422);
        }

        $fields = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'unit_type' => 'sometimes|in:piece,kg,liter',
        ]);

        $name = ConsumableMaterial::normalizeName($fields['name'] ?? $consumableMaterial->name);
        if ($name === '') {
            return response()->json(['message' => 'نام ماده الزامی است.'], 422);
        }

        $unit = $fields['unit_type'] ?? $consumableMaterial->unitType();
        if (! in_array($unit, [RawMaterial::UNIT_PIECE, RawMaterial::UNIT_KG, RawMaterial::UNIT_LITER], true)) {
            $unit = RawMaterial::UNIT_PIECE;
        }
        if ($unit !== RawMaterial::UNIT_KG && ! Schema::hasColumn('raw_materials', 'unit_type')) {
            return response()->json([
                'message' => 'ستون واحد مواد اولیه آماده نیست و انتقال با واحد '.$consumableMaterial->unitLabelFa().' ممکن نیست.',
            ], 422);
        }

        $moveBlocked = $this->moveToRawBlockReason($consumableMaterial);
        if ($moveBlocked) {
            return response()->json(['message' => $moveBlocked], 422);
        }

        $lots = $consumableMaterial->lots()->get();
        $invoiceIds = $lots->pluck('invoice_id')->filter()->unique()->values();
        if ($invoiceIds->isNotEmpty() && ! Schema::hasColumn('raw_material_lots', 'invoice_id')) {
            return response()->json([
                'message' => 'اتصال فاکتور به مواد اولیه آماده نیست و انتقال موجودی این کالا موجودی را دوباره می‌سازد.',
            ], 422);
        }

        try {
            $raw = DB::transaction(function () use ($consumableMaterial, $name, $unit) {
                $lots = $consumableMaterial->lots()->lockForUpdate()->get();
                $stillBlocked = $this->moveToRawBlockReason($consumableMaterial);
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
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => '«'.$name.'» به انبار مواد اولیه منتقل شد.',
            'raw_material_id' => $raw->id,
        ], 200);
    }

    public function destroy(Request $request, ConsumableMaterial $consumableMaterial)
    {
        $this->assertModelBelongsToStaffAtelier($request, $consumableMaterial);

        $hasStock = $consumableMaterial->lots()->where('remaining', '>', 0)->exists();
        if ($hasStock) {
            return response()->json(['message' => 'ابتدا موجودی را برداشت کنید؛ ماده با موجودی قابل حذف نیست.'], 422);
        }

        $hasWithdrawals = $consumableMaterial->withdrawals()->exists();
        if ($hasWithdrawals) {
            return response()->json(['message' => 'این ماده هیستوری برداشت دارد و قابل حذف نیست.'], 422);
        }

        if (Schema::hasColumn('produced_good_ingredients', 'consumable_material_id')
            && \App\Models\ProducedGoodIngredient::query()->where('consumable_material_id', $consumableMaterial->id)->exists()) {
            return response()->json(['message' => 'این کالا در فرمول کالای تولیدی استفاده شده و قابل حذف نیست.'], 422);
        }

        $consumableMaterial->lots()->delete();
        $consumableMaterial->delete();

        return response()->json(['message' => 'حذف شد.'], 200);
    }

    public function withdraw(Request $request, ConsumableMaterial $consumableMaterial, ConsumableFifoService $fifo)
    {
        $this->assertModelBelongsToStaffAtelier($request, $consumableMaterial);

        $fields = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'note' => 'nullable|string',
            'withdrawn_at' => 'nullable|date',
            'date' => 'nullable|date',
        ]);

        $when = $fields['withdrawn_at'] ?? $fields['date'] ?? null;
        try {
            $withdrawnAt = $when ? Carbon::parse($when) : now();
            $withdrawal = $fifo->withdraw(
                $consumableMaterial,
                (float) $fields['quantity'],
                $fields['note'] ?? null,
                $withdrawnAt
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->serializeWithdrawal($withdrawal), 201);
    }

    public function withdrawals(Request $request)
    {
        if (! Schema::hasTable('consumable_withdrawals')) {
            return response([
                'summary' => ['count' => 0, 'total_cost' => 0, 'total_quantity' => 0],
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 30,
                'total' => 0,
            ], 200);
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = ConsumableWithdrawal::query()
            ->where('atelier_id', $atelierId)
            ->with(['material', 'slices.lot'])
            ->orderByDesc('withdrawn_at')
            ->orderByDesc('id');

        if ($request->filled('consumable_material_id')) {
            $query->where('consumable_material_id', (int) $request->input('consumable_material_id'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('withdrawn_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('withdrawn_at', '<=', $request->input('to_date'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('note', 'like', '%'.$search.'%')
                        ->orWhereHas('material', function ($m) use ($search) {
                            $m->where('name', 'like', '%'.$search.'%');
                        });
                });
            }
        }

        $summary = [
            'count' => (clone $query)->count(),
            'total_cost' => round((float) (clone $query)->sum('total_cost'), 2),
            'total_quantity' => round((float) (clone $query)->sum('quantity'), 3),
        ];

        $perPage = (int) $request->input('per_page', 30);
        $perPage = $perPage > 0 ? min($perPage, 100) : 30;
        $page = $query->paginate($perPage);
        $page->getCollection()->transform(function (ConsumableWithdrawal $row) {
            return $this->serializeWithdrawal($row);
        });

        return response([
            'summary' => $summary,
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ], 200);
    }

    protected function serializeWithdrawal(ConsumableWithdrawal $withdrawal): array
    {
        $withdrawal->loadMissing(['material', 'slices.lot']);

        return [
            'id' => $withdrawal->id,
            'consumable_material_id' => $withdrawal->consumable_material_id,
            'material_name' => $withdrawal->material->name ?? null,
            'quantity' => (float) $withdrawal->quantity,
            'total_cost' => (float) $withdrawal->total_cost,
            'withdrawn_at' => optional($withdrawal->withdrawn_at)->toDateTimeString(),
            'note' => $withdrawal->note,
            'slices' => $withdrawal->slices->map(function ($slice) {
                return [
                    'id' => $slice->id,
                    'lot_id' => $slice->consumable_material_lot_id,
                    'quantity' => (float) $slice->quantity,
                    'unit_cost' => (float) $slice->unit_cost,
                    'cost' => (float) $slice->cost,
                ];
            })->values(),
            'created_at' => optional($withdrawal->created_at)->toDateTimeString(),
        ];
    }

    protected function moveToRawBlockReason(ConsumableMaterial $material): ?string
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
