<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProductPlanController as PublicProductPlanController;
use App\Models\Customer;
use App\Models\ProductPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProductPlanController extends Controller
{
    public function index(Request $request)
    {
        $this->requirePlatformAdmin($request);
        if (! Schema::hasTable('product_plans')) {
            return response(['data' => [], 'message' => 'جدول پلن محصول ساخته نشده است. SQL را اجرا کنید.'], 200);
        }
        $slug = trim((string) $request->query('product', ''));
        $includeInactive = $request->boolean('include_inactive', true);
        $formatter = app(PublicProductPlanController::class);

        $plans = ProductPlan::query()
            ->when($slug !== '', fn ($q) => $q->forProduct($slug))
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductPlan $plan) => $formatter->format($plan));

        return response(['data' => $plans], 200);
    }

    public function store(Request $request)
    {
        $this->requirePlatformAdmin($request);
        $plan = ProductPlan::create($this->validated($request));

        return response(['data' => app(PublicProductPlanController::class)->format($plan)], 201);
    }

    public function update(Request $request, ProductPlan $productPlan)
    {
        $this->requirePlatformAdmin($request);
        $productPlan->update($this->validated($request, true));

        return response([
            'data' => app(PublicProductPlanController::class)->format($productPlan->fresh()),
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $fields = $request->validate([
            'product' => 'sometimes|string|max:32',
            'product_slug' => 'sometimes|string|max:32',
            'name' => $required.'|string|max:255',
            'max_users' => 'sometimes|integer|min:1|max:10000',
            'max_videos' => 'sometimes|integer|min:1|max:50',
            'duration_days' => $required.'|integer|min:1|max:3650',
            'duration_label' => 'nullable|string|max:32',
            'price_rial' => 'nullable|integer|min:0',
            'price_toman' => 'nullable|integer|min:0',
            'price' => 'nullable|integer|min:0',
            'features' => 'nullable',
            'description' => 'nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
        ]);

        $slug = $fields['product_slug'] ?? $fields['product'] ?? ProductPlan::PRODUCT_CLASS;
        $priceRial = $fields['price_rial'] ?? null;
        if ($priceRial === null) {
            $toman = $fields['price_toman'] ?? $fields['price'] ?? null;
            if ($toman !== null) {
                $priceRial = ((int) $toman) * 10;
            }
        }
        if ($priceRial === null && ! $partial) {
            $priceRial = 0;
        }

        $features = $fields['features'] ?? null;
        if (is_string($features)) {
            $decoded = json_decode($features, true);
            if (is_array($decoded)) {
                $features = $decoded;
            } else {
                $features = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $features) ?: [])));
            }
        }

        $out = [
            'product_slug' => $slug,
            'name' => $fields['name'] ?? null,
            'max_users' => $fields['max_users'] ?? null,
            'max_videos' => $fields['max_videos'] ?? null,
            'duration_days' => $fields['duration_days'] ?? null,
            'duration_label' => $fields['duration_label'] ?? null,
            'description' => $fields['description'] ?? null,
        ];
        if ($priceRial !== null) {
            $out['price_rial'] = (int) $priceRial;
        }
        if ($features !== null) {
            $out['features'] = $features;
        }
        if (array_key_exists('is_active', $fields)) {
            $out['is_active'] = (bool) $fields['is_active'];
        }
        if (array_key_exists('sort_order', $fields)) {
            $out['sort_order'] = (int) $fields['sort_order'];
        }

        return array_filter($out, fn ($value) => $value !== null);
    }

    protected function requirePlatformAdmin(Request $request): User
    {
        $actor = $this->shopRequestActor($request);
        if ($actor instanceof Customer) {
            abort(response()->json(['message' => 'این عملیات فقط برای ادمین است.'], 403));
        }
        if (! $actor instanceof User) {
            abort(response()->json(['message' => 'لطفاً وارد شوید.'], 401));
        }
        if (! $actor->roles()->where('id', User::USER_TYPE_KEY['ادمین'])->exists()) {
            abort(response()->json(['message' => 'فقط ادمین می‌تواند پلن محصول را مدیریت کند.'], 403));
        }

        return $actor;
    }
}
