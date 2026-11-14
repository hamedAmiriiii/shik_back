<?php

namespace App\Http\Controllers;

use App\Models\InvoiceItem;
use App\Models\ProducedGoodIngredient;
use App\Models\ProductionConsumption;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use App\Services\ProducedGoodCostService;
use App\Services\RawMaterialFifoService;
use App\Services\RawMaterialInvoiceService;
use App\Tools\ProductQuantityTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use RuntimeException;

class RawMaterialController extends Controller
{
    public function index(Request $request, RawMaterialFifoService $fifo)
    {
        $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::PRODUCED_GOODS, 'کالای تولیدی برای این فروشگاه فعال نیست.');
        if (! Schema::hasTable('raw_materials')) {
            return response()->json([
                'message' => 'جدول raw_materials وجود ندارد. migration یا SQL را اجرا کنید.',
            ], 503);
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);

        $lotRel = $request->boolean('with_all_lots') ? 'lots' : 'openLots';
        $with = [$lotRel];
        if (Schema::hasColumn('raw_material_lots', 'invoice_id')) {
            $with[] = $lotRel.'.invoice.payments';
            $with[] = $lotRel.'.invoice.shopAccount';
            $with[] = $lotRel.'.invoice.cheque';
            $with[] = $lotRel.'.invoiceItem';
        }

        $query = RawMaterial::query()
            ->where('atelier_id', $atelierId)
            ->with($with)
            ->orderByDesc('id');

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

        $this->prepareRawMaterialPurchaseRequest($request);

        $fields = $request->validate(
            array_merge([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('raw_materials', 'name')->where('atelier_id', $atelierId),
                ],
                'note' => 'nullable|string',
                'unit_type' => ['nullable', Rule::in([RawMaterial::UNIT_KG, RawMaterial::UNIT_LITER, RawMaterial::UNIT_PIECE])],
                'sale_price' => 'nullable|numeric|min:0',
                'quantity_kg' => 'nullable|numeric|min:0.001',
                'price_per_kg' => 'nullable|numeric|min:0|required_with:quantity_kg',
                'purchased_at' => 'nullable|date',
            ], $this->rawMaterialPurchaseAccountRules(false), RawMaterialInvoiceService::requestRules()),
            $this->rawMaterialPurchaseMessages()
        );

        $fields = $this->prepareRawMaterialPurchase($fields);

        $unitType = $fields['unit_type'] ?? RawMaterial::UNIT_KG;
        if (in_array($unitType, [RawMaterial::UNIT_PIECE, RawMaterial::UNIT_LITER], true)
            && ! Schema::hasColumn('raw_materials', 'unit_type')) {
            return response()->json([
                'message' => 'ستون unit_type در جدول raw_materials وجود ندارد. migration یا SQL مربوط را اجرا کنید.',
            ], 503);
        }
        if ($unitType === RawMaterial::UNIT_PIECE && ! empty($fields['quantity_kg'])
            && ! ProductQuantityTools::isWholeNumber($fields['quantity_kg'])) {
            return response()->json([
                'message' => 'تعداد ماده اولیه تعدادی باید عدد صحیح باشد.',
            ], 422);
        }

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
            $material = DB::transaction(function () use ($atelierId, $fields, $invoices, $request, $unitType) {
                $payload = [
                    'atelier_id' => $atelierId,
                    'name' => $fields['name'],
                    'sale_price' => $fields['sale_price'] ?? 0,
                    'note' => $fields['note'] ?? null,
                ];
                if (Schema::hasColumn('raw_materials', 'unit_type')) {
                    $payload['unit_type'] = $unitType;
                }
                $material = RawMaterial::create($payload);

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
            'unit_type' => ['sometimes', Rule::in([RawMaterial::UNIT_KG, RawMaterial::UNIT_LITER, RawMaterial::UNIT_PIECE])],
        ]);

        $unitChanging = false;
        $newUnit = null;
        if (array_key_exists('unit_type', $fields)) {
            if (! Schema::hasColumn('raw_materials', 'unit_type')) {
                unset($fields['unit_type']);
            } elseif ($fields['unit_type'] !== $rawMaterial->unitType()) {
                $blocked = $rawMaterial->unitChangeBlockReason();
                if ($blocked) {
                    return response()->json(['message' => $blocked], 422);
                }
                $unitChanging = true;
                $newUnit = $fields['unit_type'];
            }
        }

        DB::transaction(function () use ($rawMaterial, $fields, $unitChanging, $newUnit) {
            $rawMaterial->update($fields);

            if ($unitChanging && $newUnit && Schema::hasColumn('invoice_items', 'unit_type')) {
                $itemIds = $rawMaterial->lots()
                    ->whereNotNull('invoice_item_id')
                    ->pluck('invoice_item_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                if ($itemIds !== []) {
                    InvoiceItem::query()->whereIn('id', $itemIds)->update(['unit_type' => $newUnit]);
                }
            }
        });

        return response($fifo->attachStock($rawMaterial->fresh()->load($this->lotRelations())), 200);
    }

    /**
     * عکس لایه (PNG شفاف، نمای کنار) برای منوی سه‌بعدی.
     * POST /api/raw-materials/{rawMaterial}/layer-image
     */
    public function uploadLayerImage(Request $request, RawMaterial $rawMaterial, RawMaterialFifoService $fifo)
    {
        $this->assertModelBelongsToStaffAtelier($request, $rawMaterial);

        if (! Schema::hasColumn('raw_materials', 'layer_image_path')) {
            return response()->json([
                'message' => 'ستون layer_image_path وجود ندارد. migration یا SQL منوی سه‌بعدی را اجرا کنید.',
            ], 503);
        }

        $ext = 'png';
        $content = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $mime = (string) $file->getMimeType();
            if (! in_array($mime, ['image/png', 'image/webp'], true)) {
                return response()->json(['message' => 'عکس لایه باید PNG یا WebP با پس‌زمینهٔ شفاف باشد.'], 422);
            }
            $ext = $mime === 'image/webp' ? 'webp' : 'png';
            $content = file_get_contents($file->getRealPath());
        } else {
            $raw = (string) $request->input('image_base64', $request->input('image', ''));
            if ($raw === '') {
                return response()->json(['message' => 'فایل عکس را ارسال کنید.'], 422);
            }
            if (strpos($raw, ',') !== false) {
                $header = strtolower(strstr($raw, ',', true) ?: '');
                $raw = substr($raw, strpos($raw, ',') + 1);
                if (strpos($header, 'webp') !== false) {
                    $ext = 'webp';
                } elseif (strpos($header, 'png') === false) {
                    return response()->json(['message' => 'عکس لایه باید PNG یا WebP با پس‌زمینهٔ شفاف باشد.'], 422);
                }
            }
            $content = base64_decode($raw, true);
        }

        if ($content === false || $content === null || $content === '') {
            return response()->json(['message' => 'عکس نامعتبر است.'], 422);
        }
        if (strlen($content) > 3 * 1024 * 1024) {
            return response()->json(['message' => 'حجم عکس لایه نباید بیشتر از ۳ مگابایت باشد.'], 422);
        }

        $this->removeLayerImageFile($rawMaterial);

        $path = \App\Tools\ImageTools::saveFile(
            "/raw-materials/{$rawMaterial->id}/layer_".time().".{$ext}",
            $content
        );
        $rawMaterial->update(['layer_image_path' => $path]);

        return response($fifo->attachStock($rawMaterial->fresh()->load($this->lotRelations())), 200);
    }

    /**
     * DELETE /api/raw-materials/{rawMaterial}/layer-image
     */
    public function deleteLayerImage(Request $request, RawMaterial $rawMaterial, RawMaterialFifoService $fifo)
    {
        $this->assertModelBelongsToStaffAtelier($request, $rawMaterial);

        if (Schema::hasColumn('raw_materials', 'layer_image_path')) {
            $this->removeLayerImageFile($rawMaterial);
            $rawMaterial->update(['layer_image_path' => null]);
        }

        return response($fifo->attachStock($rawMaterial->fresh()->load($this->lotRelations())), 200);
    }

    private function removeLayerImageFile(RawMaterial $rawMaterial): void
    {
        $path = $rawMaterial->getAttributes()['layer_image_path'] ?? null;
        if ($path && \Illuminate\Support\Facades\Storage::exists('public/'.$path)) {
            \Illuminate\Support\Facades\Storage::delete('public/'.$path);
        }
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

    /**
     * خرید چند ماده اولیه با یک فاکتور جمعی.
     * POST /api/raw-materials/bulk-purchase
     * body: { lines: [{raw_material_id, quantity_kg, price_per_kg}], create_invoice, vat_*, payment_* ... }
     */
    public function bulkPurchase(Request $request, RawMaterialFifoService $fifo, RawMaterialInvoiceService $invoices)
    {
        $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::PRODUCED_GOODS, 'کالای تولیدی برای این فروشگاه فعال نیست.');
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت خرید فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $this->prepareRawMaterialPurchaseRequest($request);

        $fields = $request->validate(
            array_merge([
                'lines' => 'required|array|min:1',
                'lines.*.raw_material_id' => 'required|integer',
                'lines.*.quantity_kg' => 'required|numeric|min:0.001',
                'lines.*.price_per_kg' => 'required|numeric|min:0',
                'lines.*.note' => 'nullable|string',
                'purchased_at' => 'nullable|date',
                'note' => 'nullable|string',
            ], $this->rawMaterialPurchaseAccountRules(true), RawMaterialInvoiceService::requestRules()),
            $this->rawMaterialPurchaseMessages()
        );

        $fields['create_invoice'] = true;
        unset($fields['invoice_id'], $fields['invoice_link'], $fields['invoice_item_id']);

        $accountError = $this->paymentAccountError($atelierId, $fields['shop_account_id'] ?? null);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        $lineRows = $fields['lines'];
        unset($fields['lines']);

        try {
            $result = DB::transaction(function () use ($atelierId, $lineRows, $fields, $invoices, $request, $fifo) {
                $attachRows = [];
                $materials = [];

                foreach ($lineRows as $index => $line) {
                    $material = RawMaterial::query()
                        ->where('atelier_id', $atelierId)
                        ->where('id', (int) $line['raw_material_id'])
                        ->first();
                    if (! $material) {
                        throw new RuntimeException('ماده اولیه ردیف '.($index + 1).' یافت نشد.');
                    }
                    if ($material->isPieceUnit() && ! ProductQuantityTools::isWholeNumber($line['quantity_kg'])) {
                        throw new RuntimeException('تعداد «'.$material->name.'» باید عدد صحیح باشد.');
                    }

                    $lot = $this->createLot($material, [
                        'quantity_kg' => $line['quantity_kg'],
                        'price_per_kg' => $line['price_per_kg'],
                        'purchased_at' => $fields['purchased_at'] ?? now(),
                        'note' => $line['note'] ?? ($fields['note'] ?? null),
                    ]);
                    $attachRows[] = ['material' => $material, 'lot' => $lot];
                    $materials[$material->id] = $material;
                }

                $invoice = $invoices->attachBulk($attachRows, $fields, $this->shopRequestActor($request));

                $stock = [];
                foreach ($materials as $material) {
                    $stock[] = $fifo->attachStock($material->load($this->lotRelations()));
                }

                return [
                    'invoice' => $invoice,
                    'materials' => array_values($stock),
                ];
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'فاکتور جمعی و موجودی مواد ثبت شد.',
            'invoice' => $result['invoice'],
            'materials' => $result['materials'],
        ], 201);
    }

    public function storeLot(Request $request, RawMaterial $rawMaterial, RawMaterialFifoService $fifo, RawMaterialInvoiceService $invoices)
    {
        $this->assertModelBelongsToStaffAtelier($request, $rawMaterial);

        $this->prepareRawMaterialPurchaseRequest($request);

        if ($rawMaterial->isPieceUnit() && ! ProductQuantityTools::isWholeNumber($request->input('quantity_kg'))) {
            return response()->json([
                'message' => 'تعداد ماده اولیه تعدادی باید عدد صحیح باشد.',
            ], 422);
        }

        $fields = $request->validate(
            array_merge([
                'quantity_kg' => 'required|numeric|min:0.001',
                'price_per_kg' => 'required|numeric|min:0',
                'purchased_at' => 'nullable|date',
                'note' => 'nullable|string',
            ], $this->rawMaterialPurchaseAccountRules(true), RawMaterialInvoiceService::requestRules()),
            $this->rawMaterialPurchaseMessages()
        );

        $fields = $this->prepareRawMaterialPurchase($fields);

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

        $invoiceId = $lot->invoice_id;
        $lot->delete();
        if ($invoiceId) {
            $invoice = \App\Models\Invoice::find($invoiceId);
            if ($invoice) {
                \App\Services\AccountingDocumentPoster::syncInvoice($invoice->fresh(['payments', 'rawMaterialLots']));
            }
        }

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
            $with[] = 'lots.invoice.payments';
            $with[] = 'lots.invoice.shopAccount';
            $with[] = 'lots.invoice.cheque';
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

    /**
     * حساب فروشگاه فقط برای پرداخت نقدی الزامی است (چک/نسیه/ترکیبی مثل فاکتور).
     *
     * @return array<string, mixed>
     */
    private function rawMaterialPurchaseAccountRules(bool $quantityAlwaysRequired): array
    {
        $rules = $this->paymentAccountRules('invoices');
        $cashNeedsAccount = function () use ($quantityAlwaysRequired) {
            if (request()->filled('invoice_id')) {
                return false;
            }
            $hasQty = $quantityAlwaysRequired || request()->filled('quantity_kg');
            if (! $hasQty) {
                return false;
            }
            if (request()->filled('payments')
                || request()->filled('cash_amount')
                || request()->filled('cheque_amount')
                || request()->filled('credit_amount')) {
                return false;
            }
            $method = \App\Services\DocumentPaymentService::normalizeMethod(request()->input('payment_method'))
                ?: (string) request()->input('payment_method', 'account');

            return $method === \App\Services\DocumentPaymentService::METHOD_ACCOUNT || $method === 'account';
        };

        $rules['shop_account_id'] = [
            Rule::requiredIf($cashNeedsAccount),
            'nullable',
            'integer',
            'exists:shop_accounts,id',
        ];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function rawMaterialPurchaseMessages(): array
    {
        return [
            'shop_account_id.required' => 'برای پرداخت نقدی ماده اولیه باید حساب فروشگاه را انتخاب کنید.',
            'shop_account_id.exists' => 'حساب انتخاب‌شده معتبر نیست.',
            'price_per_kg.required_with' => 'برای ثبت مقدار خرید باید قیمت هر کیلو را وارد کنید.',
        ];
    }

    /**
     * خرید لات بدون فاکتور موجود → فاکتور جدید با همان مدل پرداخت فاکتور (نقد/چک/نسیه/ترکیبی).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function prepareRawMaterialPurchase(array $fields): array
    {
        if (empty($fields['quantity_kg'])) {
            return $fields;
        }

        if (empty($fields['invoice_id']) && ! array_key_exists('create_invoice', $fields)) {
            $fields['create_invoice'] = true;
        }

        return $fields;
    }

    private function prepareRawMaterialPurchaseRequest(Request $request): void
    {
        $this->mergeRequestPayload($request, [
            'name', 'unit_type', 'note', 'sale_price', 'quantity_kg', 'price_per_kg', 'purchased_at',
            'create_invoice', 'invoice_id', 'invoice_link', 'invoice_item_id', 'invoice_title',
            'date', 'invoice_date', 'lines',
            'vat_mode', 'vat_percent', 'vat_amount',
            'shop_account_id', 'payment_method', 'cheque', 'cheque_id',
            'payments', 'cash_amount', 'cheque_amount', 'credit_amount',
            'beneficiary_id', 'user_shiksho_id',
        ]);

        $payments = $request->input('payments');
        if (is_string($payments) && $payments !== '') {
            $decoded = json_decode($payments, true);
            if (is_array($decoded)) {
                $request->merge(['payments' => $decoded]);
            }
        }

        $cheque = $request->input('cheque');
        if (is_string($cheque) && $cheque !== '') {
            $decoded = json_decode($cheque, true);
            if (is_array($decoded)) {
                $request->merge(['cheque' => $decoded]);
            }
        }

        $lines = $request->input('lines');
        if (is_string($lines) && $lines !== '') {
            $decoded = json_decode($lines, true);
            if (is_array($decoded)) {
                $request->merge(['lines' => $decoded]);
            }
        }
    }
}
