<?php

namespace App\Services;

use App\Exceptions\InsufficientShopSmsQuotaException;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class ShopSmsQuotaService
{
    public const SETTING_KEY = 'shop_sms_quota';

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

        return self::getBalance($atelierId);
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

        return $balance;
    }
}
