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
     * افزودن یا به‌روزرسانی اعتبار اقساطی کاربر
     */
    public function store(Request $request)
    {
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        $request->validate([
            'phone' => 'required|string|digits:11',
            'credit' => 'required|numeric|min:0',
        ]);

        $phone = $request->input('phone');
        $credit = (float) $request->input('credit');

        // ایجاد یا به‌روزرسانی اعتبار اقساطی
        $user = UserShiksho::firstOrCreate(
            ['phone' => $phone],
            ['credit' => 0, 'installment_credit' => 0, 'credit_last_updated_at' => now()]
        );

        // مقدار جدید اعتبار اقساطی جایگزین می‌شود
        $oldCredit = $user->installment_credit;
        $user->installment_credit = $credit;
        $user->save();

        // ارسال پیامک به کاربر
        $creditFormatted = number_format($credit, 0);
        $text = "شیک شو\nاعتبار خرید اقساطی شما تا سقف {$creditFormatted} تومان در شیک شو شارژ شد";
        SmsTools::sendShopSms($phone, $text, null, $credit, 'installment_credit');

        return response([
            'message' => 'اعتبار اقساطی با موفقیت ثبت شد',
            'user' => $user,
            'old_installment_credit' => $oldCredit,
            'new_installment_credit' => $credit,
        ], 201);
    }

    /**
     * به‌روزرسانی اعتبار اقساطی کاربر
     */
    public function update(Request $request, $phone)
    {
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        $request->validate([
            'credit' => 'required|numeric|min:0',
        ]);

        $user = UserShiksho::where('phone', $phone)->first();

        if (!$user) {
            return response([
                'error' => 'کاربر یافت نشد'
            ], 404);
        }

        $oldCredit = $user->installment_credit;
        $newCredit = (float) $request->input('credit');

        $user->installment_credit = $newCredit;
        $user->save();

        // ارسال پیامک به کاربر
        $creditFormatted = number_format($newCredit, 0);
        $text = "شیک شو\nاعتبار خرید اقساطی شما به {$creditFormatted} تومان به‌روزرسانی شد";
        SmsTools::sendShopSms($phone, $text, null, $newCredit, 'installment_credit');

        return response([
            'message' => 'اعتبار اقساطی با موفقیت به‌روزرسانی شد',
            'user' => $user,
            'old_installment_credit' => $oldCredit,
            'new_installment_credit' => $newCredit,
        ], 200);
    }

    /**
     * حذف اعتبار اقساطی کاربر (صفر کردن)
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

        $oldCredit = $user->installment_credit;
        $user->installment_credit = 0;
        $user->save();

        return response([
            'message' => 'اعتبار اقساطی با موفقیت حذف شد',
            'user' => $user,
            'old_installment_credit' => $oldCredit,
        ], 200);
    }
}

