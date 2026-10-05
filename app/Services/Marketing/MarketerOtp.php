<?php

namespace App\Services\Marketing;

use App\Tools\SmsTools;
use Illuminate\Support\Facades\Cache;

class MarketerOtp
{
    private const OTP_PREFIX = 'marketer_login_otp:';

    private const OTP_ATTEMPTS_PREFIX = 'marketer_login_otp_attempts:';

    private const OTP_COOLDOWN_PREFIX = 'marketer_login_otp_sent_at:';

    private const OTP_TTL_MINUTES = 5;

    public const RESEND_SECONDS = 90;

    private const MAX_ATTEMPTS = 5;

    /**
     * @return int ثانیه‌های باقی‌مانده تا امکان ارسال دوباره؛ صفر یعنی کد ارسال شد
     */
    public function send(string $phone): int
    {
        $cooldownKey = self::OTP_COOLDOWN_PREFIX.$phone;
        $lastSent = Cache::get($cooldownKey);
        if ($lastSent !== null && (time() - (int) $lastSent) < self::RESEND_SECONDS) {
            return max(1, self::RESEND_SECONDS - (time() - (int) $lastSent));
        }

        $code = (string) random_int(10000, 99999);
        Cache::put(self::OTP_PREFIX.$phone, $code, now()->addMinutes(self::OTP_TTL_MINUTES));
        Cache::forget(self::OTP_ATTEMPTS_PREFIX.$phone);
        Cache::put($cooldownKey, time(), now()->addMinutes(self::OTP_TTL_MINUTES + 1));

        SmsTools::sendSms($phone, 'وبینو - کد ورود پنل بازاریابی: '.$code);

        return 0;
    }

    /**
     * @return string|null پیام خطا یا null وقتی کد درست است
     */
    public function verify(string $phone, string $code): ?string
    {
        $code = preg_replace('/\D/', '', MarketingService::toLatinDigits($code));
        $expected = Cache::get(self::OTP_PREFIX.$phone);
        if ($expected === null) {
            return 'کد منقضی شده است. دوباره درخواست کد بدهید.';
        }
        if ((string) $expected !== (string) $code) {
            $attempts = (int) Cache::get(self::OTP_ATTEMPTS_PREFIX.$phone, 0) + 1;
            Cache::put(self::OTP_ATTEMPTS_PREFIX.$phone, $attempts, now()->addMinutes(self::OTP_TTL_MINUTES));
            if ($attempts >= self::MAX_ATTEMPTS) {
                Cache::forget(self::OTP_PREFIX.$phone);
            }

            return 'کد واردشده اشتباه است.';
        }

        Cache::forget(self::OTP_PREFIX.$phone);
        Cache::forget(self::OTP_ATTEMPTS_PREFIX.$phone);

        return null;
    }
}
