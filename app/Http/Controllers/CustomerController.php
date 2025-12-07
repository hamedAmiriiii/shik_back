<?php

namespace App\Http\Controllers;

use App\Models\CustomerPhone;
use App\Models\Purchase;
use App\Models\UserShiksho;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * لیست خریداران از فروشگاه (آنهایی که شماره تلفنشان ثبت شده)
     */
    public function index(Request $request)
    {
        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        
        // دریافت لیست خریداران با اطلاعات آماری
        $query = DB::table('purchases')
            ->select(
                'purchases.phone',
                DB::raw('COUNT(purchases.id) as total_purchases'),
                DB::raw('SUM(purchases.total_amount) as total_spent'),
                DB::raw('SUM(purchases.credit_earned) as total_credit_earned'),
                DB::raw('MAX(purchases.created_at) as last_purchase_date')
            )
            ->whereNotNull('purchases.phone')
            ->where('purchases.phone', '!=', '')
            ->groupBy('purchases.phone');

        // اعمال جستجو
        if ($searchDataModel) {
            if (is_object($searchDataModel)) {
                if (isset($searchDataModel->phone)) {
                    $query->where('purchases.phone', 'like', '%' . $searchDataModel->phone . '%');
                }
            } else if (is_string($searchDataModel)) {
                $query->where('purchases.phone', 'like', '%' . $searchDataModel . '%');
            }
        }

        // دریافت اعتبار فعلی هر مشتری
        $customers = $query->orderBy('last_purchase_date', 'desc')
            ->paginate($request->input('per_page', 50));

        // اضافه کردن اعتبار فعلی به هر مشتری
        foreach ($customers->items() as $customer) {
            $userShiksho = UserShiksho::where('phone', $customer->phone)->first();
            $customer->current_credit = $userShiksho ? $userShiksho->credit : 0;
        }

        return response($customers, 200);
    }

    /**
     * جزئیات یک مشتری خاص بر اساس شماره تلفن
     */
    public function show(Request $request, $phone)
    {
        // اطلاعات خریدهای مشتری
        $purchases = Purchase::where('phone', $phone)
            ->with('purchasedProducts.product')
            ->orderBy('id', 'desc')
            ->get();

        // اطلاعات اعتبار
        $userShiksho = UserShiksho::where('phone', $phone)->first();

        // آمار کلی
        $stats = [
            'phone' => $phone,
            'total_purchases' => $purchases->count(),
            'total_spent' => $purchases->sum('total_amount'),
            'total_credit_earned' => $purchases->sum('credit_earned'),
            'current_credit' => $userShiksho ? $userShiksho->credit : 0,
            'last_purchase_date' => $purchases->first() ? $purchases->first()->created_at : null,
        ];

        return response([
            'stats' => $stats,
            'purchases' => $purchases
        ], 200);
    }
}

