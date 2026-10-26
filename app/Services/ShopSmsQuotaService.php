<?php

namespace App\Services;

use App\Exceptions\InsufficientShopSmsQuotaException;
use App\Models\Setting;
use App\Tools\SmsTools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopSmsQuotaService
{
    public const SETTING_KEY = 'shop_sms_quota';

    /** فلگ «هشدار کمبود اعتبار پیامک برای این دوره مصرف ارسال شده» */
    public const LOW_QUOTA_NOTIFIED_KEY = 'shop_sms_low_quota_notified';

    /** وقتی موجودی به زیر این عدد برسد، یک‌بار به صاحب فروشگاه پیامک هشدار می‌رود */
    public const LOW_QUOTA_THRESHOLD = 30;

    public const CHARS_PER_SMS_PART = 70;

    /** پسوند اجباری اپراتور که هنگام ارسال به متن اضافه می‌شود */
    public const OPT_OUT_SUFFIX = " \n لغو11";

    public static function billableText(string $message): string
    {
        return $message.self::OPT_OUT_SUFFIX;
    }

    /**
     * تعداد واحد پیامک (هر ۷۰ کاراکتر = ۱ واحد، حداقل ۱ برای متن غیرخالی).
     */
    public static function countSmsParts(string $message): int
    {
        $text = self::billableText($message);
        $length = mb_strlen($text, 'UTF-8');
        if ($length === 0) {
            return 0;
        }

        return (int) ceil($length / self::CHARS_PER_SMS_PART);
    }

    public static function getBalance(int $atelierId): int
    {
        Setting::setShopContext($atelierId);

        return max(0, (int) Setting::get(self::SETTING_KEY, '0'));
    }

    public static function getSummary(int $atelierId): array
    {
        return [
            'atelier_id' => $atelierId,
            'balance' => self::getBalance($atelierId),
            'chars_per_sms' => self::CHARS_PER_SMS_PART,
            'setting_key' => self::SETTING_KEY,
        ];
    }

    public static function estimate(string $message, ?int $atelierId = null): array
    {
        $parts = self::countSmsParts($message);
        $billable = self::billableText($message);
        $balance = $atelierId !== null ? self::getBalance($atelierId) : null;

        return [
            'sms_parts' => $parts,
            'character_count' => mb_strlen($billable, 'UTF-8'),
            'chars_per_sms' => self::CHARS_PER_SMS_PART,
            'balance' => $balance,
            'can_send' => $balance === null ? null : $balance >= $parts,
        ];
    }

    public static function assertCanSend(int $atelierId, string $message): int
    {
        $parts = self::countSmsParts($message);
        if ($parts === 0) {
            return 0;
        }

        $balance = self::getBalance($atelierId);
        if ($balance < $parts) {
            throw new InsufficientShopSmsQuotaException($parts, $balance);
        }

        return $parts;
    }

    /**
     * کسر اعتبار قبل از ارسال؛ در صورت کمبود اعتبار خطا می‌دهد.
     */
    public static function deductForMessage(int $atelierId, string $message): int
    {
        $parts = self::countSmsParts($message);
        if ($parts === 0) {
            return 0;
        }

        DB::transaction(function () use ($atelierId, $parts) {
            $setting = Setting::query()
                ->where('key', self::SETTING_KEY)
                ->where('atelier_id', $atelierId)
                ->lockForUpdate()
                ->first();

            if (! $setting) {
                Setting::setShopContext($atelierId);
                Setting::set(self::SETTING_KEY, '0');
                $setting = Setting::query()
                    ->where('key', self::SETTING_KEY)
                    ->where('atelier_id', $atelierId)
                    ->lockForUpdate()
                    ->first();
            }

            $balance = max(0, (int) $setting->value);
            if ($balance < $parts) {
                throw new InsufficientShopSmsQuotaException($parts, $balance);
            }

            $setting->value = (string) ($balance - $parts);
            $setting->save();
        });

        Setting::setShopContext($atelierId);

        $newBalance = self::getBalance($atelierId);
        self::maybeNotifyLowQuota($atelierId, $newBalance);

        return $parts;
    }

    /**
     * شارژ اعتبار پیامک توسط ادمین.
     */
    public static function charge(int $atelierId, int $amount): int
    {
        if ($amount < 1) {
            return self::getBalance($atelierId);
        }

        DB::transaction(function () use ($atelierId, $amount) {
            $setting = Setting::query()
                ->where('key', self::SETTING_KEY)
                ->where('atelier_id', $atelierId)
                ->lockForUpdate()
                ->first();

            if (! $setting) {
                Setting::setShopContext($atelierId);
                Setting::set(self::SETTING_KEY, '0');
                $setting = Setting::query()
                    ->where('key', self::SETTING_KEY)
                    ->where('atelier_id', $atelierId)
                    ->lockForUpdate()
                    ->first();
            }

            $balance = max(0, (int) $setting->value);
            $setting->value = (string) ($balance + $amount);
            $setting->save();
        });

        Setting::setShopContext($atelierId);

        $newBalance = self::getBalance($atelierId);
        self::resetLowQuotaNoticeIfRecovered($atelierId, $newBalance);

        return $newBalance;
    }

    /**
     * تنظیم مستقیم موجودی پیامک (ویرایش ادمین).
     */
    public static function setBalance(int $atelierId, int $balance): int
    {
        $balance = max(0, $balance);

        DB::transaction(function () use ($atelierId, $balance) {
            Setting::setShopContext($atelierId);
            Setting::set(self::SETTING_KEY, (string) $balance);
        });

        self::resetLowQuotaNoticeIfRecovered($atelierId, $balance);

        return $balance;
    }

    /**
     * اگر موجودی دوباره به آستانه یا بالاتر رسید، فلگ هشدار پاک می‌شود
     * تا دفعهٔ بعدِ افت زیر آستانه دوباره یک پیامک برود.
     */
    public static function resetLowQuotaNoticeIfRecovered(int $atelierId, ?int $balance = null): void
    {
        $balance ??= self::getBalance($atelierId);
        if ($balance < self::LOW_QUOTA_THRESHOLD) {
            return;
        }

        self::setLowQuotaNotified($atelierId, false);
    }

    /**
     * یک‌بار در هر دورهٔ مصرف، وقتی موجودی زیر آستانه رفت به صاحب فروشگاه هشدار می‌دهد.
     */
    public static function maybeNotifyLowQuota(int $atelierId, ?int $balance = null): void
    {
        $balance ??= self::getBalance($atelierId);
        if ($balance >= self::LOW_QUOTA_THRESHOLD) {
            return;
        }

        $shouldSend = false;
        DB::transaction(function () use ($atelierId, &$shouldSend) {
            $row = Setting::query()
                ->where('key', self::LOW_QUOTA_NOTIFIED_KEY)
                ->where('atelier_id', $atelierId)
                ->lockForUpdate()
                ->first();

            if ($row && (string) $row->value === '1') {
                return;
            }

            Setting::setShopContext($atelierId);
            Setting::set(self::LOW_QUOTA_NOTIFIED_KEY, '1');
            $shouldSend = true;
        });

        if (! $shouldSend) {
            return;
        }

        try {
            $phone = self::resolveOwnerPhone($atelierId);
            if ($phone === null) {
                Log::warning('shop sms low-quota notify skipped: no owner phone', [
                    'atelier_id' => $atelierId,
                    'balance' => $balance,
                ]);
                self::setLowQuotaNotified($atelierId, false);

                return;
            }

            $shopName = SmsTools::shopSmsBrand($atelierId);
            $result = SmsTools::sendLowSmsQuotaAlert($phone, $shopName, $balance);
            if (! ($result['ok'] ?? false)) {
                Log::warning('shop sms low-quota notify failed', [
                    'atelier_id' => $atelierId,
                    'balance' => $balance,
                    'result' => $result,
                ]);
                self::setLowQuotaNotified($atelierId, false);
            }
        } catch (\Throwable $e) {
            Log::warning('shop sms low-quota notify exception', [
                'atelier_id' => $atelierId,
                'balance' => $balance,
                'error' => $e->getMessage(),
            ]);
            self::setLowQuotaNotified($atelierId, false);
        }
    }

    public static function isLowQuotaNotified(int $atelierId): bool
    {
        Setting::setShopContext($atelierId);

        return (string) Setting::get(self::LOW_QUOTA_NOTIFIED_KEY, '0') === '1';
    }

    protected static function setLowQuotaNotified(int $atelierId, bool $notified): void
    {
        Setting::setShopContext($atelierId);
        Setting::set(self::LOW_QUOTA_NOTIFIED_KEY, $notified ? '1' : '0');
    }

    public static function resolveOwnerPhone(int $atelierId): ?string
    {
        return ShopStaffAccess::resolveShopCreatorPhone($atelierId);
    }
}
