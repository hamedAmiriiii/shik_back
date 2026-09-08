<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ProductPlanOrder;
use App\Models\User;
use App\Services\ProductPlanOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProductPlanOrderController extends Controller
{
    public function index(Request $request, ProductPlanOrderService $orders)
    {
        $this->requirePlatformAdmin($request);
        if (! Schema::hasTable('product_plan_orders')) {
            return response(['data' => []], 200);
        }

        $status = trim((string) $request->query('status', ''));
        $search = trim((string) $request->query('q', ''));

        $rows = ProductPlanOrder::query()
            ->with('plan')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->map(fn (ProductPlanOrder $order) => $orders->format($order));

        return response(['data' => $rows], 200);
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
            abort(response()->json(['message' => 'فقط ادمین می‌تواند سفارش‌ها را ببیند.'], 403));
        }

        return $actor;
    }
}
