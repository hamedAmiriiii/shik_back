<?php

namespace App\Http\Controllers\Repair;

use App\Http\Controllers\Controller;
use App\Models\RepairSetting;
use App\Models\RepairUser;
use App\Tools\PhoneTools;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class RepairAuthController extends Controller
{
    private const OTP_PREFIX = 'repair_login_otp:';

    private const OTP_ATTEMPTS_PREFIX = 'repair_login_otp_attempts:';

    private const OTP_COOLDOWN_PREFIX = 'repair_login_otp_sent_at:';

    private const OTP_TTL_MINUTES = 5;

    private const OTP_RESEND_SECONDS = 90;

    private const OTP_MAX_ATTEMPTS = 5;

    public function config()
    {
        $this->assertSchema();
        $values = RepairSetting::allValues();

        return response([
            'brand_name' => $values['brand_name'],
            'support_phone' => $values['support_phone'],
            'categories' => RepairSetting::categories($values),
            'online_payment_enabled' => $values['online_payment_enabled'] === '1',
            'card_payment_enabled' => $values['card_payment_enabled'] === '1',
        ]);
    }

    public function sendCode(Request $request)
    {
        $this->assertSchema();
        $data = $request->validate(['phone' => 'required|string|max:20']);
        $phone = PhoneTools::normalizeIranPhone($data['phone']);
        if (! PhoneTools::isValidIranMobile($phone)) {
            return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }

        $existing = RepairUser::query()->where('phone', $phone)->first();
        if ($existing && ! $existing->is_active) {
            return response()->json(['message' => 'حساب شما غیرفعال است.'], 403);
        }

        $cooldownKey = self::OTP_COOLDOWN_PREFIX.$phone;
        $lastSent = Cache::get($cooldownKey);
        if ($lastSent !== null && (time() - (int) $lastSent) < self::OTP_RESEND_SECONDS) {
            return response([
                'message' => 'لطفاً قبل از درخواست مجدد چند لحظه صبر کنید.',
                'retry_after_seconds' => max(1, self::OTP_RESEND_SECONDS - (time() - (int) $lastSent)),
            ], 429);
        }

        $code = (string) random_int(10000, 99999);
        Cache::put(self::OTP_PREFIX.$phone, $code, now()->addMinutes(self::OTP_TTL_MINUTES));
        Cache::forget(self::OTP_ATTEMPTS_PREFIX.$phone);
        Cache::put($cooldownKey, time(), now()->addMinutes(self::OTP_TTL_MINUTES + 1));

        $brand = RepairSetting::value('brand_name') ?: (string) config('repair.brand_name');
        SmsTools::sendSms($phone, $brand.' - کد ورود: '.$code);

        return response([
            'message' => 'کد ورود به شمارهٔ شما ارسال شد.',
            'is_new' => $existing === null && ! $this->isAdminPhone($phone),
            'retry_after_seconds' => self::OTP_RESEND_SECONDS,
        ], 201);
    }

    public function verify(Request $request)
    {
        $this->assertSchema();
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'code' => 'required|string|max:10',
            'name' => 'nullable|string|max:150',
        ]);
        $phone = PhoneTools::normalizeIranPhone($data['phone']);
        if (! PhoneTools::isValidIranMobile($phone)) {
            return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }

        $expected = Cache::get(self::OTP_PREFIX.$phone);
        $code = preg_replace('/\D/', '', strtr((string) $data['code'], ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
        if ($expected === null) {
            return response()->json(['message' => 'کد منقضی شده است. دوباره درخواست کد بدهید.'], 422);
        }
        if ((string) $expected !== (string) $code) {
            $attempts = (int) Cache::get(self::OTP_ATTEMPTS_PREFIX.$phone, 0) + 1;
            Cache::put(self::OTP_ATTEMPTS_PREFIX.$phone, $attempts, now()->addMinutes(self::OTP_TTL_MINUTES));
            if ($attempts >= self::OTP_MAX_ATTEMPTS) {
                Cache::forget(self::OTP_PREFIX.$phone);
            }

            return response()->json(['message' => 'کد واردشده اشتباه است.'], 422);
        }

        Cache::forget(self::OTP_PREFIX.$phone);
        Cache::forget(self::OTP_ATTEMPTS_PREFIX.$phone);

        $user = RepairUser::query()->where('phone', $phone)->first();
        if ($user && ! $user->is_active) {
            return response()->json(['message' => 'حساب شما غیرفعال است.'], 403);
        }

        if ($this->isAdminPhone($phone)) {
            if (! $user) {
                $user = RepairUser::create([
                    'role' => RepairUser::ROLE_ADMIN,
                    'phone' => $phone,
                    'name' => $data['name'] ?? 'مدیر',
                ]);
            } elseif (! $user->isAdmin()) {
                $user->update(['role' => RepairUser::ROLE_ADMIN]);
            }
        } elseif (! $user) {
            $user = RepairUser::create([
                'role' => RepairUser::ROLE_CUSTOMER,
                'phone' => $phone,
                'name' => $data['name'] ?? null,
            ]);
        } elseif (! $user->name && ! empty($data['name'])) {
            $user->name = $data['name'];
        }

        $user->last_login_at = now();
        $user->save();

        $token = $user->createToken('repair-'.$user->role)->plainTextToken;

        return response([
            'token' => $token,
            'user' => $user->toSessionArray(),
        ], 201);
    }

    public function me(Request $request)
    {
        /** @var RepairUser $user */
        $user = $request->user();

        return response(['user' => $user->toSessionArray()]);
    }

    public function updateProfile(Request $request)
    {
        /** @var RepairUser $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'address' => 'nullable|string|max:1000',
        ]);
        $user->update($data);

        return response(['user' => $user->fresh()->toSessionArray()]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response(['message' => 'خارج شدید.']);
    }

    private function isAdminPhone(string $phone): bool
    {
        foreach (config('repair.admin_phones', []) as $adminPhone) {
            if (PhoneTools::normalizeIranPhone($adminPhone) === $phone) {
                return true;
            }
        }

        return false;
    }

    private function assertSchema(): void
    {
        if (! Schema::hasTable('repair_users')) {
            abort(response()->json([
                'message' => 'جداول سامانهٔ تعمیرکار ساخته نشده‌اند. migration یا database/sql/create_repair_tables_manual.sql را اجرا کنید.',
            ], 503));
        }
    }
}
