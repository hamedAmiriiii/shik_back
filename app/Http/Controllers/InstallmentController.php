<?php

namespace App\Http\Controllers;

use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Customer;
use App\Models\UserShiksho;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

class InstallmentController extends Controller
{
    /**
     * بررسی اینکه کاربر یک ادمین است (نه Customer)
     */
    private function checkAdmin(Request $request)
    {
        $user = $request->user();
        
        // بررسی اینکه کاربر یک Customer نیست
        if ($user instanceof Customer) {
            return response([
                'error' => 'این endpoint فقط برای ادمین است'
            ], 403);
        }
        
        // بررسی اینکه کاربر یک User (ادمین) است
        if (!($user instanceof User)) {
            return response([
                'error' => 'دسترسی غیرمجاز'
            ], 403);
        }
        
        return null;
    }

    /**
     * لیست قسط‌های یک خرید
     */
    public function index(Request $request, Purchase $purchase)
    {
        // بررسی دسترسی ادمین
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }
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
        // بررسی دسترسی ادمین
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        // بررسی اینکه قسط متعلق به این خرید باشد
        if ($installment->purchase_id !== $purchase->id) {
            return response(['error' => 'این قسط متعلق به این خرید نیست'], 400);
        }

        // بررسی اینکه قسط قبلاً پرداخت نشده باشد
        if ($installment->is_paid) {
            return response(['error' => 'این قسط قبلاً پرداخت شده است'], 400);
        }

        DB::beginTransaction();
        try {
            // علامت‌گذاری قسط به عنوان پرداخت شده
            $installment->update([
                'is_paid' => true,
                'paid_at' => now(),
                'notes' => $request->input('notes'),
            ]);

            // افزودن اعتبار اقساطی به کاربر (مبلغ قسط پرداخت شده)
            if ($purchase->phone) {
                $userShiksho = UserShiksho::where('phone', $purchase->phone)->first();
                if ($userShiksho) {
                    // افزودن مبلغ قسط به اعتبار اقساطی کاربر
                    $userShiksho->addInstallmentCredit($installment->amount);
                }
            }

            DB::commit();

            // بارگذاری مجدد
            $installment->load('purchase');

            return response([
                'message' => 'قسط با موفقیت پرداخت شد',
                'installment' => $installment,
                'purchase' => $purchase->load('installments'),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response([
                'error' => 'خطا در پرداخت قسط',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * لیست قسط‌های یک مشتری بر اساس شماره تلفن
     */
    public function getByPhone(Request $request)
    {
        // بررسی دسترسی ادمین
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        $request->validate([
            'phone' => 'required|string|digits:11',
        ]);

        $phone = $request->input('phone');

        $purchases = Purchase::where('phone', $phone)
            ->where('payment_type', 'installment')
            ->with(['installments' => function($query) {
                $query->orderBy('installment_number');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response([
            'phone' => $phone,
            'purchases' => $purchases,
        ], 200);
    }

    /**
     * لیست قسط‌های پرداخت نشده (برای ادمین)
     */
    public function unpaid(Request $request)
    {
        // بررسی دسترسی ادمین
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        $query = Installment::where('is_paid', false)
            ->with('purchase')
            ->orderBy('due_date');

        // فیلتر بر اساس تاریخ سررسید
        if ($request->has('overdue')) {
            $query->where('due_date', '<', now()->toDateString());
        }

        if ($request->has('due_soon')) {
            $threeDaysLater = now()->addDays(3)->toDateString();
            $query->whereBetween('due_date', [now()->toDateString(), $threeDaysLater]);
        }

        $installments = $query->paginate($request->input('per_page', 20));

        return response($installments, 200);
    }
}

