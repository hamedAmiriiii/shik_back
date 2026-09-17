<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ShopPlan;
use App\Models\User;
use App\Services\GatewayPaymentService;
use App\Support\ProjectType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ShopPlanController extends Controller
{
    public function index(Request $request, GatewayPaymentService $payments)
    {
        $this->requirePlatformAdmin($request);

        $includeInactive = $request->boolean('include_inactive');
        $projectType = $request->filled('project_type')
            ? ProjectType::normalize($request->input('project_type'))
            : null;

        $plans = ShopPlan::query()
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->when($projectType !== null, fn ($q) => $q->forProject($projectType))
            ->orderBy('project_type')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ShopPlan $p) => $payments->formatShopPlan($p));

        return response(['data' => $plans, 'shop_plans' => $plans], 200);
    }

    public function store(Request $request, GatewayPaymentService $payments)
    {
        $this->requirePlatformAdmin($request);
        $fields = $this->validated($request);
        $plan = ShopPlan::create($fields);

        return response(['data' => $payments->formatShopPlan($plan)], 201);
    }

    public function update(Request $request, ShopPlan $shopPlan, GatewayPaymentService $payments)
    {
        $this->requirePlatformAdmin($request);
        $fields = $this->validated($request, true);
        if ($fields !== []) {
            $shopPlan->update($fields);
        }

        return response(['data' => $payments->formatShopPlan($shopPlan->fresh())], 200);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $rules = [
            'name' => $required.'|string|max:255',
            'title' => 'sometimes|nullable|string|max:255',
            'duration_days' => $required.'|integer|min:1|max:3650',
            'price_rial' => 'sometimes|nullable|integer|min:0',
            'price_toman' => 'sometimes|nullable|integer|min:0',
            'price' => 'sometimes|nullable|integer|min:0',
            'discount_price_rial' => 'sometimes|nullable|integer|min:0',
            'discount_price_toman' => 'sometimes|nullable|integer|min:0',
            'description' => 'sometimes|nullable|string|max:500',
            'is_active' => 'sometimes|boolean',
            'unlimited_products' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:255',
        ];
        if (Schema::hasColumn('shop_plans', 'project_type')) {
            $rules['project_type'] = [$partial ? 'sometimes' : 'required', 'string', Rule::in([ProjectType::SHOP, ProjectType::OIL])];
        }

        $fields = $request->validate($rules);

        if (isset($fields['title']) && (! isset($fields['name']) || trim((string) $fields['name']) === '')) {
            $fields['name'] = $fields['title'];
        }
        unset($fields['title']);

        $priceRial = $this->resolvePriceRial($request, $fields);
        if ($priceRial !== null) {
            $fields['price_rial'] = $priceRial;
        } elseif (! $partial) {
            abort(response()->json(['message' => 'مبلغ فروش را وارد کنید.'], 422));
        }
        unset($fields['price_toman'], $fields['price']);

        if (Schema::hasColumn('shop_plans', 'discount_price_rial')) {
            $discountRial = $this->resolveDiscountPriceRial($request, $fields);
            if ($request->exists('discount_price_toman')
                || $request->exists('discount_price_rial')
                || array_key_exists('discount_price_rial', $fields)
                || array_key_exists('discount_price_toman', $fields)
            ) {
                $fields['discount_price_rial'] = $discountRial;
            }
        }
        unset($fields['discount_price_toman']);

        if (isset($fields['project_type'])) {
            $fields['project_type'] = ProjectType::normalize($fields['project_type']);
        } elseif (! $partial && Schema::hasColumn('shop_plans', 'project_type')) {
            $fields['project_type'] = ProjectType::SHOP;
        }

        if (isset($fields['description'])) {
            $desc = trim((string) $fields['description']);
            $fields['description'] = $desc !== '' ? $desc : null;
        }

        if (array_key_exists('unlimited_products', $fields)) {
            $fields['unlimited_products'] = (bool) $fields['unlimited_products'];
        }

        if (! Schema::hasColumn('shop_plans', 'description')) {
            unset($fields['description']);
        }
        if (! Schema::hasColumn('shop_plans', 'discount_price_rial')) {
            unset($fields['discount_price_rial']);
        }
        if (! Schema::hasColumn('shop_plans', 'project_type')) {
            unset($fields['project_type']);
        }
        if (! Schema::hasColumn('shop_plans', 'unlimited_products')) {
            unset($fields['unlimited_products']);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function resolvePriceRial(Request $request, array $fields): ?int
    {
        if ($request->exists('price_rial') || array_key_exists('price_rial', $fields)) {
            $v = $fields['price_rial'] ?? $request->input('price_rial');
            if ($v === null || $v === '') {
                return null;
            }

            return max(0, (int) $v);
        }
        if ($request->exists('price_toman') || array_key_exists('price_toman', $fields)) {
            $v = $fields['price_toman'] ?? $request->input('price_toman');
            if ($v === null || $v === '') {
                return null;
            }

            return max(0, (int) $v) * 10;
        }
        if ($request->exists('price') || array_key_exists('price', $fields)) {
            $v = $fields['price'] ?? $request->input('price');
            if ($v === null || $v === '') {
                return null;
            }
            $n = (int) $v;
            // اگر خیلی بزرگ باشد احتمالاً ریال است
            return $n >= 100000000 ? $n : max(0, $n) * 10;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function resolveDiscountPriceRial(Request $request, array $fields): ?int
    {
        if ($request->exists('discount_price_rial') || array_key_exists('discount_price_rial', $fields)) {
            $v = $fields['discount_price_rial'] ?? $request->input('discount_price_rial');
            if ($v === null || $v === '') {
                return null;
            }

            return max(0, (int) $v);
        }
        if ($request->exists('discount_price_toman') || array_key_exists('discount_price_toman', $fields)) {
            $v = $fields['discount_price_toman'] ?? $request->input('discount_price_toman');
            if ($v === null || $v === '') {
                return null;
            }

            return max(0, (int) $v) * 10;
        }

        return null;
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
            abort(response()->json(['message' => 'فقط ادمین می‌تواند پلن اکانت را مدیریت کند.'], 403));
        }

        return $actor;
    }
}
