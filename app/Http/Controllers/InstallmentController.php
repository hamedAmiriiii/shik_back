<?php

namespace App\Http\Controllers;

use App\Models\Installment;
use App\Models\Purchase;
use App\Models\UserShiksho;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstallmentController extends Controller
{
    /**
     * لیست قسط‌های یک خرید
     */
    public function index(Request $request, Purchase $purchase)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $purchase);

        $installments = $purchase->installments()->orderBy('installment_number')->get();

        return response([
            'purchase' => $purchase,
            'installments' => $installments,
            'paid_amount' => $purchase->paid_amount,
            'remaining_amount' => $purchase->remaining_amount,
        ], 200);
    }

    /**
     * پرداخت یک قسط
     */
    public function pay(Request $request, Purchase $purchase, Installment $installment)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $purchase);

        if ($installment->purchase_id !== $purchase->id) {
            return response()->json(['message' => 'این قسط متعلق به این خرید نیست'], 400);
        }

        if ($installment->is_paid) {
            return response()->json(['message' => 'این قسط قبلاً پرداخت شده است'], 400);
        }

        DB::beginTransaction();
        try {
            $installment->update([
                'is_paid' => true,
                'paid_at' => now(),
                'notes' => $request->input('notes'),
            ]);

            if ($purchase->phone) {
                $atelierId = $purchase->atelier_id;
                $userQuery = UserShiksho::where('phone', $purchase->phone);
                if ($atelierId) {
                    $userQuery->where('atelier_id', $atelierId);
                }
                $userShiksho = $userQuery->first();
                if ($userShiksho) {
                    $userShiksho->addInstallmentCredit($installment->amount);
                }
            }

            DB::commit();

            $installment->load('purchase');

            return response([
                'message' => 'قسط با موفقیت پرداخت شد',
                'installment' => $installment,
                'purchase' => $purchase->load('installments'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'خطا در پرداخت قسط',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * لیست قسط‌های یک مشتری بر اساس شماره تلفن
     */
    public function getByPhone(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $request->validate([
            'phone' => 'required|string|digits:11',
        ]);

        $phone = $request->input('phone');

        $purchases = Purchase::forAtelier($atelierId)
            ->where('phone', $phone)
            ->where('payment_type', 'installment')
            ->with(['installments' => function ($query) {
                $query->orderBy('installment_number');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response([
            'phone' => $phone,
            'atelier_id' => $atelierId,
            'purchases' => $purchases,
        ], 200);
    }

    /**
     * لیست قسط‌های پرداخت نشده (همان فروشگاه)
     */
    public function unpaid(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = Installment::where('is_paid', false)
            ->whereHas('purchase', function ($q) use ($atelierId) {
                $q->forAtelier($atelierId);
            })
            ->with(['purchase' => function ($q) {
                $q->select('id', 'phone', 'total_amount', 'payment_type', 'atelier_id', 'created_at');
            }])
            ->orderBy('due_date');

        if ($request->boolean('overdue')) {
            $query->where('due_date', '<', now()->toDateString());
        }

        if ($request->boolean('due_soon')) {
            $threeDaysLater = now()->addDays(3)->toDateString();
            $query->whereBetween('due_date', [now()->toDateString(), $threeDaysLater]);
        }

        $installments = $query->paginate($request->input('per_page', 20));

        return response($installments, 200);
    }
}
