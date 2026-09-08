<?php

namespace App\Http\Controllers;

use App\Models\ProductPlan;
use App\Services\ProductPlanOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProductPlanController extends Controller
{
    public function index(Request $request)
    {
        $slug = trim((string) $request->query('product', ProductPlan::PRODUCT_CLASS));
        if ($slug === '') {
            $slug = ProductPlan::PRODUCT_CLASS;
        }

        if (! Schema::hasTable('product_plans')) {
            return response([
                'product' => $slug,
                'currency' => 'IRR',
                'data' => [],
            ], 200);
        }

        $plans = ProductPlan::query()
            ->forProduct($slug)
            ->active()
            ->whereIn('duration_days', [180, 365])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductPlan $plan) => $this->format($plan));

        return response([
            'product' => $slug,
            'currency' => 'IRR',
            'data' => $plans,
        ], 200);
    }

    public function purchase(Request $request, ProductPlanOrderService $orders)
    {
        $fields = $request->validate([
            'plan_id' => 'required|integer|min:1',
            'item_id' => 'nullable|integer|min:1',
            'email' => 'required|email|max:190',
            'phone' => 'required|string|max:20',
            'return_url' => 'nullable|string|max:1024',
        ]);
        $phone = $orders->normalizePhone((string) $fields['phone']);
        if (! preg_match('/^09\d{9}$/', $phone)) {
            return response(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }

        try {
            $payload = $orders->start(
                (int) ($fields['plan_id'] ?? $fields['item_id']),
                (string) $fields['email'],
                $phone,
                $fields['return_url'] ?? null
            );
        } catch (RuntimeException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'به درگاه زرین‌پال هدایت شوید.',
            'payment_url' => $payload['payment_url'],
            'authority' => $payload['authority'],
            'order' => $payload,
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public function format(ProductPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'product' => $plan->product_slug,
            'name' => $plan->name,
            'max_users' => (int) $plan->max_users,
            'max_videos' => (int) $plan->max_videos,
            'duration_days' => (int) $plan->duration_days,
            'duration_label' => $plan->duration_label,
            'price_rial' => (int) $plan->price_rial,
            'price_toman' => (int) floor(((int) $plan->price_rial) / 10),
            'features' => is_array($plan->features) ? array_values($plan->features) : [],
            'description' => $plan->description,
            'is_active' => (bool) $plan->is_active,
            'sort_order' => (int) $plan->sort_order,
        ];
    }
}
