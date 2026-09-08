<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ShopPlan;
use App\Models\User;
use App\Services\GatewayPaymentService;
use Illuminate\Http\Request;

class ShopPlanController extends Controller
{
    public function index(Request $request, GatewayPaymentService $payments)
    {
        $this->requirePlatformAdmin($request);

        $includeInactive = $request->boolean('include_inactive');
        $plans = ShopPlan::query()
            ->when(! $includeInactive, fn ($q) => $q->active())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ShopPlan $p) => $payments->formatShopPlan($p));

        return response(['data' => $plans], 200);
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
        $shopPlan->update($this->validated($request, true));

        return response(['data' => $payments->formatShopPlan($shopPlan->fresh())], 200);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => $required.'|string|max:255',
            'duration_days' => $required.'|integer|min:1|max:3650',
            'price_rial' => $required.'|integer|min:1000',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:255',
        ]);
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
