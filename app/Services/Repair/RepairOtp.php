<?php

namespace App\Services\Repair;

use App\Models\RepairSetting;
use App\Models\RepairSmsLog;
use Illuminate\Support\Facades\Cache;

class RepairOtp
{
    private const OTP_PREFIX = 'repair_login_otp:';

    private const OTP_ATTEMPTS_PREFIX = 'repair_login_otp_attempts:';

    private const OTP_COOLDOWN_PREFIX = 'repair_login_otp_sent_at:';

    private const OTP_TTL_MINUTES = 5;

    public const RESEND_SECONDS = 90;

    private const MAX_ATTEMPTS = 5;

    /**
     * @return int ثانیه‌های باقی‌مانده تا ارسال مجدد؛ صفر یعنی ارسال شد
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

        $brand = RepairSetting::value('brand_name') ?: (string) config('repair.brand_name');
        app(RepairSms::class)->send($phone, $brand.' - کد ورود: '.$code, RepairSmsLog::TYPE_OTP, true);

        return 0;
    }

    /**
     * @return string|null پیام خطا؛ null یعنی کد درست بود و مصرف شد
     */
    public function verify(string $phone, string $code): ?string
    {
        $code = preg_replace('/\D/', '', strtr($code, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]));
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
