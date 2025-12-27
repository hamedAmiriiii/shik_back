<?php

namespace App\Http\Controllers;

use App\Models\ConfirmationCode;
use App\Models\Customer;
use App\Tools\SmsTools;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CustomerRegisterController extends Controller
{
    /**
     * مرحله 1: ارسال کد تایید به شماره تلفن
     */
    public function sendVerificationCode(Request $request)
    {
        $request->validate([
            'phone' => 'required|numeric|digits:11'
        ]);

        // بررسی محدودیت ارسال کد
        $countToday = ConfirmationCode::whereDate('created_at', Carbon::today())
            ->where('phone', $request->input('phone'))
            ->count();
        
        if ($countToday >= 10) {
            return response([
                'message' => 'تعداد درخواست‌های مجاز به پایان رسیده است. لطفاً ساعاتی دیگر تلاش کنید.'
            ], 400);
        }

        $countThreeMinutesAgo = ConfirmationCode::where('phone', $request->input('phone'))
            ->whereBetween('created_at', [now()->subMinutes(3), now()])
            ->count();
        
        if ($countThreeMinutesAgo > 0) {
            return response([
                'message' => 'شما در سه دقیقه اخیر درخواستی داشته‌اید. لطفاً کمی صبر کنید.'
            ], 400);
        }

        // ایجاد کد تایید
        $confirmationCode = ConfirmationCode::create([
            'phone' => $request->input('phone'),
            'code' => mt_rand(10000, 99999)
        ]);

        // ارسال SMS
        $text = "کد تایید ثبت‌نام شما: $confirmationCode->code";
        $smsResult = SmsTools::sendSms($request->input('phone'), $text);

        return response([
            'message' => 'کد تایید با موفقیت ارسال شد',
            'sms_result' => $smsResult
        ], 201);
    }

    /**
     * مرحله 2: بررسی کد و ثبت اطلاعات تکمیلی
     */
    public function verifyAndRegister(Request $request)
    {
        $request->validate([
            'phone' => 'required|numeric|digits:11',
            'code' => 'required|numeric|digits:5',
            'password' => 'required|string|min:6',
            'name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'national_code' => 'nullable|string|digits:10',
            'state_id' => 'nullable|exists:states,id',
            'city_id' => 'nullable|exists:cities,id',
            'address' => 'nullable|string',
        ]);

        // بررسی کد تایید
        $codeExists = ConfirmationCode::where('phone', $request->input('phone'))
            ->whereBetween('created_at', [now()->subMinutes(3), now()])
            ->where('code', $request->input('code'))
            ->exists();

        if (!$codeExists) {
            return response([
                'error' => 'کد تایید نامعتبر است یا منقضی شده است'
            ], 400);
        }

        // بررسی اینکه آیا مشتری قبلاً ثبت‌نام کرده است
        $customer = Customer::where('phone', $request->input('phone'))->first();

        if ($customer) {
            // اگر قبلاً ثبت‌نام کرده، فقط اطلاعات را به‌روزرسانی می‌کنیم
            $updateData = [
                'password' => Hash::make($request->input('password')),
                'is_verified' => true,
            ];
            
            // فقط فیلدهایی که ارسال شده‌اند را اضافه می‌کنیم
            if ($request->filled('name')) {
                $updateData['name'] = $request->input('name');
            }
            if ($request->filled('last_name')) {
                $updateData['last_name'] = $request->input('last_name');
            }
            if ($request->filled('national_code')) {
                $updateData['national_code'] = $request->input('national_code');
            }
            if ($request->filled('state_id')) {
                $updateData['state_id'] = $request->input('state_id');
            }
            if ($request->filled('city_id')) {
                $updateData['city_id'] = $request->input('city_id');
            }
            if ($request->filled('address')) {
                $updateData['address'] = $request->input('address');
            }
            
            $customer->update($updateData);
        } else {
            // ایجاد مشتری جدید
            $createData = [
                'phone' => $request->input('phone'),
                'password' => Hash::make($request->input('password')),
                'is_verified' => true,
            ];
            
            // فقط فیلدهایی که ارسال شده‌اند را اضافه می‌کنیم
            if ($request->filled('name')) {
                $createData['name'] = $request->input('name');
            }
            if ($request->filled('last_name')) {
                $createData['last_name'] = $request->input('last_name');
            }
            if ($request->filled('national_code')) {
                $createData['national_code'] = $request->input('national_code');
            }
            if ($request->filled('state_id')) {
                $createData['state_id'] = $request->input('state_id');
            }
            if ($request->filled('city_id')) {
                $createData['city_id'] = $request->input('city_id');
            }
            if ($request->filled('address')) {
                $createData['address'] = $request->input('address');
            }
            
            $customer = Customer::create($createData);
        }

        // بارگذاری روابط
        $customer->load(['state', 'city']);

        return response([
            'message' => 'ثبت‌نام با موفقیت انجام شد',
            'customer' => $customer
        ], 201);
    }

    /**
     * بررسی اینکه آیا شماره تلفن قبلاً ثبت‌نام کرده است
     */
    public function checkPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|numeric|digits:11'
        ]);

        $customer = Customer::where('phone', $request->input('phone'))->first();

        if ($customer && $customer->is_verified) {
            return response([
                'exists' => true,
                'is_verified' => true,
                'customer' => $customer->load(['state', 'city'])
            ]);
        }

        return response([
            'exists' => false,
            'is_verified' => false
        ]);
    }

    /**
     * لاگین مشتری با استفاده از شماره تلفن و رمز عبور
     */
    public function verifyAndLogin(Request $request)
    {
        $request->validate([
            'phone' => 'required|numeric|digits:11',
            'password' => 'required|string',
        ]);

        // بررسی مشتری
        $customer = Customer::where('phone', $request->input('phone'))
            ->where('is_verified', true)
            ->first();

        // بررسی رمز عبور
        if (!$customer || !Hash::check($request->input('password'), $customer->password)) {
            return response([
                'error' => 'شماره تلفن یا رمز عبور اشتباه است'
            ], 401);
        }

        // ایجاد token برای مشتری
        $token = $customer->createToken('customer-token')->plainTextToken;

        // بارگذاری روابط
        $customer->load(['state', 'city']);

        return response([
            'message' => 'ورود با موفقیت انجام شد',
            'customer' => $customer,
            'token' => $token
        ], 200);
    }

    /**
     * خروج مشتری (حذف token)
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        
        // بررسی اینکه کاربر یک Customer است
        if (!($user instanceof Customer)) {
            return response([
                'error' => 'این endpoint فقط برای مشتریان است'
            ], 403);
        }

        // حذف تمام token‌های مشتری
        $user->tokens()->delete();

        return response([
            'message' => 'خروج با موفقیت انجام شد'
        ], 200);
    }
}

