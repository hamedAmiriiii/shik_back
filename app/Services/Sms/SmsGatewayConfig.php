<?php

namespace App\Services\Sms;

use App\Models\Setting;

/**
 * تنظیمات سراسری درگاه پیامک (atelier_id = null).
 */
class SmsGatewayConfig
{
    public const PROVIDER_SHINA = 'shina';

    public const PROVIDER_MELIPAYAMAK = 'melipayamak';

    public const KEY_PROVIDER = 'sms_provider';

    public const KEY_MELI_USERNAME = 'melipayamak_username';

    public const KEY_MELI_API_KEY = 'melipayamak_api_key';

    public const KEY_MELI_FROM = 'melipayamak_from';

    public const KEY_MELI_OTP_BODY_ID = 'melipayamak_otp_body_id';

    public const KEY_MELI_CREDIT_BODY_ID = 'melipayamak_credit_body_id';

    public const KEY_MELI_LOW_QUOTA_BODY_ID = 'melipayamak_low_quota_body_id';

    public const KEY_MELI_OTP_VARS_MODE = 'melipayamak_otp_vars_mode';

    public static function providers(): array
    {
        return [
            self::PROVIDER_SHINA => 'شینا پیامک',
            self::PROVIDER_MELIPAYAMAK => 'ملی پیامک',
        ];
    }

    public static function provider(): string
    {
        $fromDb = trim((string) self::globalGet(self::KEY_PROVIDER, ''));
        if ($fromDb === self::PROVIDER_MELIPAYAMAK || $fromDb === self::PROVIDER_SHINA) {
            return $fromDb;
        }

        $fromEnv = strtolower(trim((string) config('sms.default_provider', self::PROVIDER_SHINA)));

        return $fromEnv === self::PROVIDER_MELIPAYAMAK
            ? self::PROVIDER_MELIPAYAMAK
            : self::PROVIDER_SHINA;
    }

    public static function isMelipayamak(): bool
    {
        return self::provider() === self::PROVIDER_MELIPAYAMAK;
    }

    public static function melipayamakUsername(): string
    {
        $v = trim((string) self::globalGet(self::KEY_MELI_USERNAME, ''));
        if ($v !== '') {
            return self::normalizeMelipayamakUsername($v);
        }

        return self::normalizeMelipayamakUsername((string) config('sms.melipayamak.username', ''));
    }

    public static function melipayamakApiKey(): string
    {
        $v = trim((string) self::globalGet(self::KEY_MELI_API_KEY, ''));
        if ($v !== '') {
            return $v;
        }

        return trim((string) config('sms.melipayamak.api_key', ''));
    }

    public static function melipayamakFrom(): string
    {
        $v = trim((string) self::globalGet(self::KEY_MELI_FROM, ''));
        if ($v !== '') {
            return $v;
        }

        return trim((string) config('sms.melipayamak.from', ''));
    }

    public static function melipayamakOtpBodyId(): ?int
    {
        $v = trim((string) self::globalGet(self::KEY_MELI_OTP_BODY_ID, ''));
        if ($v === '') {
            $v = trim((string) config('sms.melipayamak.otp_body_id', ''));
        }
        if ($v === '' || ! ctype_digit($v)) {
            return null;
        }

        return (int) $v;
    }

    public static function melipayamakCreditBodyId(): ?int
    {
        $v = trim((string) self::globalGet(self::KEY_MELI_CREDIT_BODY_ID, ''));
        if ($v === '') {
            $v = trim((string) config('sms.melipayamak.credit_body_id', ''));
        }
        if ($v === '' || ! ctype_digit($v)) {
            return null;
        }

        return (int) $v;
    }

    public static function melipayamakLowQuotaBodyId(): ?int
    {
        $v = trim((string) self::globalGet(self::KEY_MELI_LOW_QUOTA_BODY_ID, ''));
        if ($v === '') {
            $v = trim((string) config('sms.melipayamak.low_quota_body_id', ''));
        }
        if ($v === '' || ! ctype_digit($v)) {
            return null;
        }

        return (int) $v;
    }

    /** code | shop_code */
    public static function melipayamakOtpVarsMode(): string
    {
        $v = trim((string) self::globalGet(self::KEY_MELI_OTP_VARS_MODE, ''));
        if ($v === '') {
            $v = trim((string) config('sms.melipayamak.otp_vars_mode', 'shop_code'));
        }

        return $v === 'code' ? 'code' : 'shop_code';
    }

    /**
     * نام کاربری همان مقدار ورود به پنل است و نباید به 98 تبدیل شود.
     */
    public static function normalizeMelipayamakUsername(string $username): string
    {
        return trim($username);
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicSnapshot(): array
    {
        $apiKey = self::melipayamakApiKey();

        return [
            'provider' => self::provider(),
            'providers' => self::providers(),
            'melipayamak' => [
                'username' => self::melipayamakUsername(),
                'api_key_set' => $apiKey !== '',
                'api_key_masked' => $apiKey !== '' ? self::maskSecret($apiKey) : '',
                'from' => self::melipayamakFrom(),
                'otp_body_id' => self::melipayamakOtpBodyId(),
                'otp_vars_mode' => self::melipayamakOtpVarsMode(),
                'credit_body_id' => self::melipayamakCreditBodyId(),
                'low_quota_body_id' => self::melipayamakLowQuotaBodyId(),
            ],
            'shina' => [
                'gateway' => (string) config('sms.shina.gateway'),
            ],
        ];
    }

    public static function save(array $input): void
    {
        $prev = Setting::contextAtelierId();
        Setting::setContextAtelierId(null);

        try {
            if (array_key_exists('provider', $input)) {
                $p = (string) $input['provider'];
                if ($p === self::PROVIDER_MELIPAYAMAK || $p === self::PROVIDER_SHINA) {
                    Setting::set(self::KEY_PROVIDER, $p);
                }
            }

            if (array_key_exists('melipayamak_username', $input)) {
                Setting::set(
                    self::KEY_MELI_USERNAME,
                    self::normalizeMelipayamakUsername((string) $input['melipayamak_username'])
                );
            }

            if (array_key_exists('melipayamak_api_key', $input)) {
                $key = trim((string) $input['melipayamak_api_key']);
                // خالی = بدون تغییر (برای اینکه ماسک را دوباره ذخیره نکنیم)
                if ($key !== '' && ! str_contains($key, '*')) {
                    Setting::set(self::KEY_MELI_API_KEY, $key);
                }
            }

            if (array_key_exists('melipayamak_from', $input)) {
                Setting::set(self::KEY_MELI_FROM, trim((string) $input['melipayamak_from']));
            }

            if (array_key_exists('melipayamak_otp_body_id', $input)) {
                $id = trim((string) $input['melipayamak_otp_body_id']);
                Setting::set(self::KEY_MELI_OTP_BODY_ID, $id === '' ? '' : (string) (int) $id);
            }

            if (array_key_exists('melipayamak_credit_body_id', $input)) {
                $id = trim((string) $input['melipayamak_credit_body_id']);
                Setting::set(self::KEY_MELI_CREDIT_BODY_ID, $id === '' ? '' : (string) (int) $id);
            }

            if (array_key_exists('melipayamak_low_quota_body_id', $input)) {
                $id = trim((string) $input['melipayamak_low_quota_body_id']);
                Setting::set(self::KEY_MELI_LOW_QUOTA_BODY_ID, $id === '' ? '' : (string) (int) $id);
            }

            if (array_key_exists('melipayamak_otp_vars_mode', $input)) {
                $mode = (string) $input['melipayamak_otp_vars_mode'];
                Setting::set(self::KEY_MELI_OTP_VARS_MODE, $mode === 'code' ? 'code' : 'shop_code');
            }
        } finally {
            Setting::setContextAtelierId($prev);
        }
    }

    protected static function globalGet(string $key, $default = null)
    {
        $prev = Setting::contextAtelierId();
        Setting::setContextAtelierId(null);
        try {
            return Setting::get($key, $default);
        } finally {
            Setting::setContextAtelierId($prev);
        }
    }

    protected static function maskSecret(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 4).str_repeat('*', max(4, $len - 8)).substr($value, -4);
    }
}
