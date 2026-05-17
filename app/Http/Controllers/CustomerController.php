<?php

namespace App\Http\Controllers;

use App\Models\Atelier;
use App\Models\CustomerPhone;
use App\Models\Purchase;
use App\Models\UserShiksho;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * ثبت کاربر در جدول user_shiksho
     */
    public function registerUserShiksho(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string|digits:11',
            'atelier_code' => 'nullable|string|max:50',
        ]);

        $atelierId = null;
        if (! empty($validated['atelier_code'])) {
            $atelierId = Atelier::where('code', $validated['atelier_code'])->value('id');
        }

        $userShiksho = UserShiksho::firstOrCreate(
            ['phone' => $validated['phone']],
            [
                'credit' => 0,
                'installment_credit' => 0,
                'credit_last_updated_at' => now(),
                'last_warning_sent_at' => null,
            ]
        );

        $smsSent = false;
        $smsError = null;
        $shopBrand = SmsTools::shopSmsBrand($atelierId ? (int) $atelierId : null);
        if ($userShiksho->wasRecentlyCreated) {
            $welcomeMessage = "به باشگاه مشتریان {$shopBrand} خوش آمدید";
            try {
                SmsTools::sendSms($validated['phone'], $welcomeMessage);
                $smsSent = true;
            } catch (\Exception $e) {
                // عدم موفقیت در ارسال پیامک نباید مانع ثبت کاربر شود
                $smsSent = false;
                $smsError = $e->getMessage();
            }
        }


        return response([
            'message' => $userShiksho->wasRecentlyCreated
                ? "کاربر با موفقیت در باشگاه مشتریان {$shopBrand} ثبت شد"
                : "کاربر قبلاً در باشگاه مشتریان {$shopBrand} ثبت شده است",
            'already_exists' => !$userShiksho->wasRecentlyCreated,
            'sms_sent' => $smsSent,
            'sms_error' => $smsError,
            'data' => $userShiksho
        ], $userShiksho->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * لیست  خریداران از فروشگاه (آنهایی که شماره تلفنشان ثبت شده)
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

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
            ->where('purchases.atelier_id', $atelierId)
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

        // اضافه کردن اعتبار فعلی به هر مشتری (همان فروشگاه)
        foreach ($customers->items() as $customer) {
            $userShiksho = UserShiksho::where('phone', $customer->phone)
                ->where('atelier_id', $atelierId)
                ->first();
            $customer->current_credit = $userShiksho ? $userShiksho->credit : 0;
        }

        return response($customers, 200);
    }

    /**
     * جزئیات یک مشتری خاص بر اساس شماره تلفن
     */
    public function show(Request $request, $phone)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        // اطلاعات خریدهای مشتری
        $purchases = Purchase::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->with('purchasedProducts.product')
            ->orderBy('id', 'desc')
            ->get();

        // اطلاعات اعتبار
        $userShiksho = UserShiksho::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

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

    /**
     * دریافت لیست مشتریان برای انتخاب (برای ارسال پیام)
     */
    public function getCustomersForBroadcast(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

       // دریافت لیست شماره تلفن‌های مشتریان همان فروشگاه
       $query = DB::table('user_shiksho')
       ->select(
           'user_shiksho.phone'
       )
       ->where('user_shiksho.atelier_id', $atelierId)
      ;

   $customers = $query->get()->map(function($item) {
       return [
           'phone' => $item->phone,
       ];
   })->values();

        return response([
            'customers' => $customers
        ], 200);
    }

    /**
     * ارسال پیام مشترک به مشتریان انتخاب شده
     */
    public function broadcastMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
            'phones' => 'required|array|min:1',
            'phones.*' => 'required|string|digits:11',
        ]);

        // دریافت لیست شماره تلفن‌های انتخاب شده
        $phones = $request->input('phones');

        if (empty($phones)) {
            return response([
                'error' => 'هیچ شماره تلفنی انتخاب نشده است'
            ], 400);
        }

        $smsAtelierId = $this->staffShopAtelierId($request);
        $smsPrefix = $smsAtelierId !== null ? SmsTools::shopSmsBrand($smsAtelierId) . "\n" : '';

        $successCount = 0;
        $failedCount = 0;
        $results = [];

        // ارسال SMS به هر شماره
        foreach ($phones as $phone) {
            try {
                $result = SmsTools::sendSms($phone, $smsPrefix . $request->input('message'));
                $successCount++;
                $results[] = [
                    'phone' => $phone,
                    'status' => 'success',
                    'result' => $result
                ];
            } catch (\Exception $e) {
                $failedCount++;
                $results[] = [
                    'phone' => $phone,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        $response = [
            'message' => 'پیام به مشتریان ارسال شد',
            'total_customers' => count($phones),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
        ];

        // فقط در صورت وجود خطا، جزئیات خطاها را برگردان
        if ($failedCount > 0 && $failedCount <= 10) {
            $response['failed_results'] = array_filter($results, function($item) {
                return $item['status'] === 'failed';
            });
        }

        return response($response, 200);
    }
}

