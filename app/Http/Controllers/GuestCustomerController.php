<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Setting;
use App\Models\UserShiksho;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;

class GuestCustomerController extends Controller
{
    /**
     * اعتبار و سفارش‌های قبلی همین فروشگاه با شماره موبایل (بدون لاگین).
     * GET|POST /api/{shop}/guest/lookup
     */
    public function lookup(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        Setting::setShopContext($atelierId);

        $phone = $this->validatedGuestPhone($request);

        $userShiksho = UserShiksho::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        $credit = $userShiksho ? (float) $userShiksho->credit : 0.0;

        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 50));

        $orders = Purchase::query()
            ->where('atelier_id', $atelierId)
            ->where('phone', $phone)
            ->with(['purchasedProducts.product', 'shopTable'])
            ->orderByDesc('id')
            ->paginate($perPage);

        $orders->withPath(url()->current());

        $payload = $orders->toArray();
        $payload['data'] = collect($orders->items())->map(
            fn (Purchase $purchase) => $this->guestPurchasePayload($purchase)
        )->values()->all();

        return response()->json([
            'phone' => $phone,
            'credit' => $credit,
            'has_credit' => $credit > 0,
            'orders' => $payload,
        ]);
    }

    private function validatedGuestPhone(Request $request): string
    {
        if ($request->has('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        $request->validate([
            'phone' => 'required|string|regex:/^09\d{9}$/',
        ]);

        return (string) $request->input('phone');
    }

    public function guestPurchasePayload(Purchase $purchase): array
    {
        $items = $purchase->purchasedProducts->map(function ($line) {
            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'name' => optional($line->product)->name,
                'quantity' => (float) $line->quantity,
                'sale_price' => (float) $line->sale_price,
                'size' => $line->size,
                'color' => $line->color,
            ];
        })->values()->all();

        return [
            'id' => $purchase->id,
            'created_at' => $purchase->created_at,
            'total_amount' => (float) $purchase->total_amount,
            'discount_amount' => (float) $purchase->discount_amount,
            'credit_used' => (float) $purchase->credit_used,
            'credit_earned' => (float) $purchase->credit_earned,
            'payable_amount' => $purchase->payableAmount(),
            'payment_type' => $purchase->payment_type,
            'is_debt_settled' => (bool) $purchase->is_debt_settled,
            'table_label' => $purchase->table_label,
            'table_number' => $purchase->shopTable ? (int) $purchase->shopTable->table_number : null,
            'items' => $items,
        ];
    }
}
