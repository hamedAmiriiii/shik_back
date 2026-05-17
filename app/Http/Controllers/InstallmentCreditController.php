<?php

namespace App\Http\Controllers;

use App\Models\UserShiksho;
use App\Tools\SmsTools;
use Illuminate\Http\Request;

class InstallmentCreditController extends Controller
{
    /**
     * بررسی اینکه کاربر یک ادمین است (نه Customer)
     */
    private function checkAdmin(Request $request)
    {
        $user = $request->user();
        
        // بررسی اینکه کاربر یک Customer نیست
        if ($user instanceof \App\Models\Customer) {
            return response([
                'error' => 'این endpoint فقط برای ادمین است'
            ], 403);
        }
        
        // بررسی اینکه کاربر یک User (ادمین) است
        if (!($user instanceof \App\Models\User)) {
            return response([
                'error' => 'دسترسی غیرمجاز'
            ], 403);
        }
        
        return null;
    }

    /**
     * لیست اعتبارات اقساطی کاربران
     */
    public function index(Request $request)
    {
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        $query = UserShiksho::query();

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس شماره تلفن
                    if (isset($searchDataModel->phone)) {
                        $q->where('phone', 'like', '%' . $searchDataModel->phone . '%');
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در شماره تلفن جستجو می‌کند
                    $q->where('phone', 'like', '%' . $searchDataModel . '%');
                }
            });
        }

        // فیلتر بر اساس اعتبار اقساطی
        if ($request->has('min_credit')) {
            $query->where('installment_credit', '>=', $request->input('min_credit'));
        }

        $users = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 20));

        return response($users, 200);
    }

    /**
     * نمایش اعتبار یک کاربر خاص
     */
    public function show(Request $request, $phone)
    {
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        $user = UserShiksho::where('phone', $phone)->first();

        if (!$user) {
            return response([
                'error' => 'کاربر یافت نشد'
            ], 404);
        }

        return response($user, 200);
    }

    /**
     * افزودن یا به‌روزرسانی اعتبار اقساطی و اعتبار عادی کاربر
     */
    public function store(Request $request)
    {
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        $request->validate([
            'phone' => 'required|string|digits:11',
            'installment_credit' => 'required|numeric|min:0',
            'credit' => 'required|numeric|min:0',
        ]);

        $phone = $request->input('phone');
        $installmentCredit = (float) $request->input('installment_credit');
        $regularCredit = (float) $request->input('credit');

        $smsAtelierId = $this->staffShopAtelierId($request);

        // ایجاد یا به‌روزرسانی اعتبار اقساطی و عادی
        $user = UserShiksho::firstOrCreate(
            ['phone' => $phone],
            ['credit' => 0, 'installment_credit' => 0, 'credit_last_updated_at' => now()]
        );

        // مقادیر جدید اعتبارات
        $oldInstallmentCredit = $user->installment_credit;
        $oldRegularCredit = $user->credit;
        $user->installment_credit = $installmentCredit;
        $user->credit = $regularCredit;
        $user->save();

        // ارسال پیامک به کاربر
        $installmentFormatted = number_format($installmentCredit, 0);
        $creditFormatted = number_format($regularCredit, 0);
        $shopName = SmsTools::shopSmsBrand($smsAtelierId);
        $text = "{$shopName}\nاعتبار خرید اقساطی شما تا {$installmentFormatted} تومان و اعتبار عادی تا {$creditFormatted} تومان شارژ شد";
        SmsTools::sendShopSms($phone, $text, null, $installmentCredit, 'installment_credit');

        return response([
            'message' => 'اعتبار اقساطی و اعتبار عادی با موفقیت ثبت شد',
            'user' => $user,
            'old_installment_credit' => $oldInstallmentCredit,
            'new_installment_credit' => $installmentCredit,
            'old_regular_credit' => $oldRegularCredit,
            'new_regular_credit' => $regularCredit,
        ], 201);
    }

    /**
     * به‌روزرسانی اعتبار اقساطی و اعتبار عادی کاربر
     */
    public function update(Request $request, $phone)
    {
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        $request->validate([
            'installment_credit' => 'nullable|numeric|min:0',
            'credit' => 'nullable|numeric|min:0',
        ]);

        $user = UserShiksho::where('phone', $phone)->first();

        if (!$user) {
            return response([
                'error' => 'کاربر یافت نشد'
            ], 404);
        }

        $oldInstallmentCredit = $user->installment_credit;
        $oldRegularCredit = $user->credit;
        
        // به‌روزرسانی اعتبار اقساطی اگر ارسال شده باشد
        if ($request->has('installment_credit')) {
            $user->installment_credit = (float) $request->input('installment_credit');
        }
        
        // به‌روزرسانی اعتبار عادی اگر ارسال شده باشد
        if ($request->has('credit')) {
            $user->credit = (float) $request->input('credit');
        }
        
        $user->save();

        $newInstallmentCredit = $user->installment_credit;
        $newRegularCredit = $user->credit;

        $smsAtelierId = $this->staffShopAtelierId($request);

        // ارسال پیامک به کاربر
        $installmentFormatted = number_format($newInstallmentCredit, 0);
        $creditFormatted = number_format($newRegularCredit, 0);
        $shopName = SmsTools::shopSmsBrand($smsAtelierId);
        $text = "{$shopName}\nاعتبار خرید اقساطی شما {$installmentFormatted} تومان و اعتبار عادی {$creditFormatted} تومان ثبت شد";
        SmsTools::sendShopSms($phone, $text, null, $newInstallmentCredit, 'installment_credit');

        return response([
            'message' => 'اعتبارات با موفقیت به‌روزرسانی شد',
            'user' => $user,
            'old_installment_credit' => $oldInstallmentCredit,
            'new_installment_credit' => $newInstallmentCredit,
            'old_regular_credit' => $oldRegularCredit,
            'new_regular_credit' => $newRegularCredit,
        ], 200);
    }

    /**
     * حذف اعتبار اقساطی و اعتبار عادی کاربر (صفر کردن)
     */
    public function destroy(Request $request, $phone)
    {
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        $user = UserShiksho::where('phone', $phone)->first();

        if (!$user) {
            return response([
                'error' => 'کاربر یافت نشد'
            ], 404);
        }

        $oldInstallmentCredit = $user->installment_credit;
        $oldRegularCredit = $user->credit;
        $user->installment_credit = 0;
        $user->credit = 0;
        $user->save();

        return response([
            'message' => 'تمام اعتبارات با موفقیت حذف شد',
            'user' => $user,
            'old_installment_credit' => $oldInstallmentCredit,
            'old_regular_credit' => $oldRegularCredit,
        ], 200);
    }
}

