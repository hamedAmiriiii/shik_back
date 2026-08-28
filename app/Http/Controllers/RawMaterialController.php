<?php

namespace App\Http\Controllers;

use App\Models\ProducedGoodIngredient;
use App\Models\ProductionConsumption;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use App\Services\ProducedGoodCostService;
use App\Services\RawMaterialFifoService;
use App\Services\RawMaterialInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use RuntimeException;

class RawMaterialController extends Controller
{
    public function index(Request $request, RawMaterialFifoService $fifo)
    {
        if (! Schema::hasTable('raw_materials')) {
            return response()->json([
                'message' => 'جدول raw_materials وجود ندارد. migration یا SQL را اجرا کنید.',
            ], 503);
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);

        $lotRel = $request->boolean('with_all_lots') ? 'lots' : 'openLots';
        $with = [$lotRel];
        if (Schema::hasColumn('raw_material_lots', 'invoice_id')) {
            $with[] = $lotRel.'.invoice';
            $with[] = $lotRel.'.invoiceItem';
        }

        $query = RawMaterial::query()
            ->where('atelier_id', $atelierId)
            ->with($with)
            ->orderBy('name');

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel) && isset($searchDataModel->name)) {
                    $q->where('name', 'like', '%'.$searchDataModel->name.'%');
                } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
                    $q->where('name', 'like', '%'.$searchDataModel.'%');
                }
            });
        } elseif ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        $attach = function ($materials) use ($fifo) {
            return $materials->each(function (RawMaterial $material) use ($fifo) {
                $fifo->attachStock($material);
            });
        };

        if ($request->boolean('paginate')) {
            $perPage = (int) $request->input('per_page', 20);
            $perPage = $perPage > 0 ? min($perPage, 100) : 20;
            $page = $query->paginate($perPage);
            $attach($page->getCollection());

            return response($page, 200);
        }

        return response($attach($query->get()), 200);
    }

    public function store(Request $request, RawMaterialFifoService $fifo, RawMaterialInvoiceService $invoices)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت ماده اولیه فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $request->validate(array_merge([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('raw_materials', 'name')->where('atelier_id', $atelierId),
            ],
            'note' => 'nullable|string',
            'sale_price' => 'nullable|numeric|min:0',
            'quantity_kg' => 'nullable|numeric|min:0.001',
            'price_per_kg' => 'nullable|numeric|min:0|required_with:quantity_kg',
            'purchased_at' => 'nullable|date',
        ], $this->paymentAccountRules('invoices'), RawMaterialInvoiceService::requestRules()));

        if ($invoices->wantsInvoice($fields) && empty($fields['quantity_kg'])) {
            return response()->json([
                'message' => 'برای صدور یا اتصال فاکتور باید مقدار و قیمت خرید را وارد کنید.',
            ], 422);
        }

        $accountError = $this->paymentAccountError($atelierId, $fields['shop_account_id'] ?? null);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        try {
            $material = DB::transaction(function () use ($atelierId, $fields, $invoices, $request) {
                $material = RawMaterial::create([
                    'atelier_id' => $atelierId,
                    'name' => $fields['name'],
                    'sale_price' => $fields['sale_price'] ?? 0,
                    'note' => $fields['note'] ?? null,
                ]);

                if (! empty($fields['quantity_kg'])) {
                    $lot = $this->createLot($material, $fields);
                    $invoices->attach($material, $lot, $fields, $this->shopRequestActor($request));
                }

                return $material;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($fifo->attachStock($material->load($this->lotRelations())), 201);
    }

    public function show(Request $request, RawMaterial $rawMaterial, ProducedGoodCostService $costService, RawMaterialFifoService $fifo)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $rawMaterial->atelier_id !== $atelierId) {
            return response(['message' => 'یافت نشد'], 404);
        }

        $fifo->attachStock($rawMaterial->load($this->lotRelations()));
        $rawMaterial->setAttribute('used_in', $costService->goodsUsingMaterial($rawMaterial)->values());

        return response($rawMaterial, 200);
    }

    public function update(Request $request, RawMaterial $rawMaterial, RawMaterialFifoService $fifo)
    {
        $this->assertModelBelongsToStaffAtelier($request, $rawMaterial);

        $atelierId = (int) $rawMaterial->atelier_id;

        $fields = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('raw_materials', 'name')
                    ->where('atelier_id', $atelierId)
                    ->ignore($rawMaterial->id),
            ],
            'sale_price' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        $rawMaterial->update($fields);

        return response($fifo->attachStock($rawMaterial->load($this->lotRelations())), 200);
    }

    public function destroy(Request $request, RawMaterial $rawMaterial)
    {
        $this->assertModelBelongsToStaffAtelier($request, $rawMaterial);

        if (ProducedGoodIngredient::where('raw_material_id', $rawMaterial->id)->exists()) {
            return response()->json([
                'message' => 'این ماده در فرمول کالاهای تولیدی استفاده شده و قابل حذف نیست.',
            ], 422);
        }

        if (ProductionConsumption::where('raw_material_id', $rawMaterial->id)->exists()) {
            return response()->json([
                'message' => 'این ماده در تولید مصرف شده و قابل حذف نیست.',
            ], 422);
        }

        if ($rawMaterial->lots()->where('remaining_kg', '>', 0)->exists()) {
            return response()->json([
                'message' => 'ابتدا موجودی لات‌های این ماده را صفر یا حذف کنید.',
            ], 422);
        }

        $rawMaterial->lots()->delete();
        $rawMaterial->delete();

        return response(['message' => 'ماده اولیه حذف شد'], 200);
    }

    public function storeLot(Request $request, RawMaterial $rawMaterial, RawMaterialFifoService $fifo, RawMaterialInvoiceService $invoices)
    {
        $this->assertModelBelongsToStaffAtelier($request, $rawMaterial);

        $fields = $request->validate(array_merge([
            'quantity_kg' => 'required|numeric|min:0.001',
            'price_per_kg' => 'required|numeric|min:0',
            'purchased_at' => 'nullable|date',
            'note' => 'nullable|string',
        ], $this->paymentAccountRules('invoices'), RawMaterialInvoiceService::requestRules()));

        $accountError = $this->paymentAccountError((int) $rawMaterial->atelier_id, $fields['shop_account_id'] ?? null);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        try {
            DB::transaction(function () use ($rawMaterial, $fields, $invoices, $request) {
                $lot = $this->createLot($rawMaterial, $fields);
                $invoices->attach($rawMaterial, $lot, $fields, $this->shopRequestActor($request));
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($fifo->attachStock($rawMaterial->load($this->lotRelations())), 201);
    }

    public function destroyLot(Request $request, RawMaterial $rawMaterial, RawMaterialLot $lot, RawMaterialFifoService $fifo)
    {
        $this->assertModelBelongsToStaffAtelier($request, $rawMaterial);

        if ((int) $lot->raw_material_id !== (int) $rawMaterial->id
            || (int) $lot->atelier_id !== (int) $rawMaterial->atelier_id) {
            return response(['message' => 'یافت نشد'], 404);
        }

        if (! $lot->isUntouched()) {
            return response()->json([
                'message' => 'این خرید قبلاً در تولید مصرف شده و قابل حذف نیست.',
            ], 422);
        }

        $lot->delete();

        return response($fifo->attachStock($rawMaterial->load($this->lotRelations())), 200);
    }

    public function updateLot(Request $request, RawMaterial $rawMaterial, RawMaterialLot $lot, RawMaterialFifoService $fifo)
    {
        $this->assertModelBelongsToStaffAtelier($request, $rawMaterial);

        if ((int) $lot->raw_material_id !== (int) $rawMaterial->id
            || (int) $lot->atelier_id !== (int) $rawMaterial->atelier_id) {
            return response(['message' => 'یافت نشد'], 404);
        }

        $fields = $request->validate([
            'price_per_kg' => 'sometimes|required|numeric|min:0',
            'purchased_at' => 'nullable|date',
            'note' => 'nullable|string',
        ]);

        try {
            $fifo->updateLot($lot, $fields);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($fifo->attachStock($rawMaterial->load($this->lotRelations())), 200);
    }

    /**
     * @return array<int, string>
     */
    private function lotRelations(): array
    {
        $with = ['lots'];
        if (Schema::hasColumn('raw_material_lots', 'invoice_id')) {
            $with[] = 'lots.invoice';
            $with[] = 'lots.invoiceItem';
        }

        return $with;
    }

    private function createLot(RawMaterial $material, array $fields): RawMaterialLot
    {
        $qty = round((float) $fields['quantity_kg'], 3);

        return $material->lots()->create([
            'atelier_id' => $material->atelier_id,
            'quantity_kg' => $qty,
            'remaining_kg' => $qty,
            'price_per_kg' => $fields['price_per_kg'],
            'purchased_at' => $fields['purchased_at'] ?? now(),
            'note' => $fields['note'] ?? null,
        ]);
    }
}
