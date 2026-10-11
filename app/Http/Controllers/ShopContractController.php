<?php

namespace App\Http\Controllers;

use App\Models\Atelier;
use App\Models\ShopContractAcceptance;
use App\Services\ShopContractService;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ShopContractController extends Controller
{
    private const OTP_PREFIX = 'shop_contract_otp:';

    private const OTP_SENT_AT_PREFIX = 'shop_contract_otp_sent_at:';

    private const OTP_ATTEMPTS_PREFIX = 'shop_contract_otp_attempts:';

    private const OTP_TTL_MINUTES = 10;

    private const OTP_RESEND_SECONDS = 60;

    private const OTP_MAX_ATTEMPTS = 5;

    /** وضعیت قرارداد + نام فروشگاه و شمارهٔ طرف مقابل برای نمایش در متن قرارداد */
    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user->atelier_id) {
            return response(['message' => 'فروشگاهی برای این حساب ثبت نشده است.'], 404);
        }

        $atelier = Atelier::find($user->atelier_id);
        $acceptance = ShopContractService::tableReady()
            ? ShopContractAcceptance::where('atelier_id', $user->atelier_id)
                ->where('contract_version', ShopContractService::VERSION)
                ->first()
            : null;

        return response([
            'version' => ShopContractService::VERSION,
            'required' => ShopContractService::isRequiredFor($user),
            'accepted' => ! ShopContractService::tableReady() || $acceptance !== null,
            'accepted_at' => $acceptance && $acceptance->accepted_at ? $acceptance->accepted_at->toIso8601String() : null,
            'shop_name' => $atelier->name ?? null,
            'phone' => $user->phone,
            'owner_name' => trim(($user->name ?? '').' '.($user->last_name ?? '')),
        ], 200);
    }

    /** ارسال کد تأیید پیامکی به شمارهٔ صاحب فروشگاه */
    public function sendCode(Request $request)
    {
        $user = $request->user();
        if (! ShopContractService::isRequiredFor($user)) {
            return response(['message' => 'تأیید قرارداد برای این حساب لازم نیست.'], 409);
        }

        $phone = (string) $user->phone;
        if ($phone === '') {
            return response(['message' => 'شمارهٔ موبایل برای این حساب ثبت نشده است.'], 422);
        }

        $sentKey = self::OTP_SENT_AT_PREFIX.$user->id;
        $lastSent = Cache::get($sentKey);
        if ($lastSent !== null && (time() - (int) $lastSent) < self::OTP_RESEND_SECONDS) {
            $wait = self::OTP_RESEND_SECONDS - (time() - (int) $lastSent);

            return response([
                'message' => 'لطفاً قبل از درخواست مجدد چند لحظه صبر کنید.',
                'retry_after_seconds' => max(1, $wait),
            ], 429);
        }

        $code = (string) random_int(10000, 99999);
        $expiresAt = now()->addMinutes(self::OTP_TTL_MINUTES);
        Cache::put(self::OTP_PREFIX.$user->id, $code, $expiresAt);
        Cache::forget(self::OTP_ATTEMPTS_PREFIX.$user->id);
        Cache::put($sentKey, time(), $expiresAt);

        SmsTools::sendSms($phone, 'کد تأیید قرارداد وبینو: '.$code);

        return response([
            'message' => 'کد تأیید به شمارهٔ شما ارسال شد.',
            'retry_after_seconds' => self::OTP_RESEND_SECONDS,
            'masked_phone' => substr($phone, 0, 4).'***'.substr($phone, -4),
        ], 201);
    }

    /** تأیید قرارداد با کد پیامکی */
    public function accept(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|numeric|digits:5',
            'agree' => 'accepted',
        ], [
            'agree.accepted' => 'برای ادامه باید متن قرارداد را بپذیرید.',
        ]);

        $user = $request->user();
        if (! ShopContractService::isRequiredFor($user)) {
            return response(['message' => 'قرارداد قبلاً تأیید شده است.', 'accepted' => true], 200);
        }

        $attemptsKey = self::OTP_ATTEMPTS_PREFIX.$user->id;
        $attempts = (int) Cache::get($attemptsKey, 0);
        $expected = Cache::get(self::OTP_PREFIX.$user->id);

        if ($expected === null) {
            return response(['message' => 'کد تأیید منقضی شده است. دوباره درخواست کد بدهید.'], 422);
        }
        if ($attempts >= self::OTP_MAX_ATTEMPTS) {
            Cache::forget(self::OTP_PREFIX.$user->id);

            return response(['message' => 'تعداد تلاش‌های اشتباه زیاد بود. دوباره درخواست کد بدهید.'], 429);
        }
        if (! hash_equals((string) $expected, (string) $data['code'])) {
            Cache::put($attemptsKey, $attempts + 1, now()->addMinutes(self::OTP_TTL_MINUTES));

            return response(['message' => 'کد تأیید اشتباه است.'], 422);
        }

        $atelier = Atelier::find($user->atelier_id);
        ShopContractAcceptance::updateOrCreate(
            [
                'atelier_id' => (int) $user->atelier_id,
                'contract_version' => ShopContractService::VERSION,
            ],
            [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'shop_name' => $atelier->name ?? null,
                'method' => 'sms_otp',
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'accepted_at' => now(),
            ]
        );

        Cache::forget(self::OTP_PREFIX.$user->id);
        Cache::forget($attemptsKey);
        Cache::forget(self::OTP_SENT_AT_PREFIX.$user->id);

        return response(['message' => 'قرارداد با موفقیت تأیید شد.', 'accepted' => true], 200);
    }
}
