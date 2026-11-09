<?php

namespace App\Http\Controllers;

use App\Models\ConsumableMaterial;
use App\Models\ConsumableWithdrawal;
use App\Services\ConsumableFifoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
        ]);

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
}
