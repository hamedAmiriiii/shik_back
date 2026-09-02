<?php

namespace App\Http\Controllers\Oil;

use App\Http\Controllers\Controller;
use App\Models\OilProduct;
use App\Support\ProjectType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class OilProductController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->oilAtelierId($request);
        $includeInactive = $request->boolean('include_inactive');

        $rows = OilProduct::query()
            ->where('atelier_id', $atelierId)
            ->when(! $includeInactive, function ($q) {
                $q->where('is_active', true);
            })
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (OilProduct $p) => $p->toApiArray())
            ->values();

        $grouped = [];
        foreach (OilProduct::kinds() as $kind => $label) {
            $grouped[] = [
                'kind' => $kind,
                'kind_label' => $label,
                'products' => $rows->where('kind', $kind)->values()->all(),
            ];
        }

        return response()->json([
            'kinds' => $grouped,
            'data' => $rows,
        ]);
    }

    public function store(Request $request)
    {
        $atelierId = $this->oilAtelierId($request);
        $fields = $request->validate([
            'kind' => ['required', 'string', Rule::in(array_keys(OilProduct::kinds()))],
            'name' => 'required|string|max:120',
        ]);

        $name = trim($fields['name']);
        if ($name === '') {
            return response()->json(['message' => 'نام محصول را وارد کنید.'], 422);
        }

        $exists = OilProduct::query()
            ->where('atelier_id', $atelierId)
            ->where('kind', $fields['kind'])
            ->where('name', $name)
            ->where('is_active', true)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'این محصول از قبل تعریف شده است.'], 422);
        }

        $max = (int) OilProduct::query()
            ->where('atelier_id', $atelierId)
            ->where('kind', $fields['kind'])
            ->max('sort_order');

        $product = OilProduct::create([
            'atelier_id' => $atelierId,
            'kind' => $fields['kind'],
            'name' => $name,
            'is_active' => true,
            'sort_order' => $max + 1,
        ]);

        return response([
            'message' => 'محصول اضافه شد.',
            'data' => $product->toApiArray(),
        ], 201);
    }

    public function update(Request $request, OilProduct $oilProduct)
    {
        $atelierId = $this->oilAtelierId($request);
        $this->assertOwned($oilProduct, $atelierId);

        $fields = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'is_active' => 'sometimes|boolean',
        ]);

        $updates = [];
        if (array_key_exists('name', $fields)) {
            $name = trim($fields['name']);
            if ($name === '') {
                return response()->json(['message' => 'نام محصول را وارد کنید.'], 422);
            }
            $updates['name'] = $name;
        }
        if (array_key_exists('is_active', $fields)) {
            $updates['is_active'] = (bool) $fields['is_active'];
        }
        if ($updates !== []) {
            $oilProduct->update($updates);
        }

        return response([
            'message' => 'ذخیره شد.',
            'data' => $oilProduct->fresh()->toApiArray(),
        ]);
    }

    public function destroy(Request $request, OilProduct $oilProduct)
    {
        $atelierId = $this->oilAtelierId($request);
        $this->assertOwned($oilProduct, $atelierId);

        if ($oilProduct->visitItems()->exists()) {
            $oilProduct->update(['is_active' => false]);

            return response([
                'message' => 'محصول از لیست انتخاب برداشته شد. در سوابق قبلی باقی می‌ماند.',
                'data' => $oilProduct->fresh()->toApiArray(),
            ]);
        }

        $oilProduct->delete();

        return response(['message' => 'محصول حذف شد.']);
    }

    private function assertOwned(OilProduct $product, int $atelierId): void
    {
        if ((int) $product->atelier_id !== $atelierId) {
            abort(response()->json(['message' => 'محصول یافت نشد.'], 404));
        }
    }

    private function oilAtelierId(Request $request): int
    {
        if (! Schema::hasTable('oil_products')) {
            abort(response()->json([
                'message' => 'جدول محصولات هنوز ساخته نشده. فایل database/sql/create_oil_products_manual.sql را اجرا کنید.',
            ], 503));
        }

        $user = $request->user();
        if (! $user || ProjectType::normalize($user->project_type) !== ProjectType::OIL) {
            abort(response()->json(['message' => 'دسترسی ندارید.'], 403));
        }
        if (! $user->atelier_id) {
            abort(response()->json(['message' => 'حساب به تعویض روغنی متصل نیست.'], 422));
        }

        return $this->shopAtelierIdOrAbort($request);
    }
}
