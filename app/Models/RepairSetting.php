<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairSetting extends Model
{
    public const DEFAULTS = [
        'brand_name' => '',
        'support_phone' => '',
        'card_number' => '',
        'card_holder' => '',
        'bank_name' => '',
        'online_payment_enabled' => '1',
        'card_payment_enabled' => '1',
        /** مرچنت کد زرین‌پال اختصاصی سامانهٔ تعمیرات (مثلاً omidtamir) */
        'zarinpal_merchant_id' => '',
        'default_labor_share_percent' => '70',
        // off: بدون نقشه | optional: اختیاری | required: انتخاب لوکیشن الزامی
        'location_mode' => 'optional',
        // auto: نشان اگر کلید داشت وگرنه OpenStreetMap | neshan | osm
        'map_provider' => 'auto',
        'categories' => "لوازم خانگی\nتأسیسات و لوله‌کشی\nبرق ساختمان\nکولر و پکیج\nسایر",
    ];

    protected $table = 'repair_settings';

    protected $fillable = ['key', 'value'];

    /**
     * @return array<string, string>
     */
    public static function allValues(): array
    {
        $values = self::DEFAULTS;
        foreach (self::query()->get(['key', 'value']) as $row) {
            if (array_key_exists($row->key, $values)) {
                $values[$row->key] = (string) $row->value;
            }
        }
        if ($values['brand_name'] === '') {
            $values['brand_name'] = (string) config('repair.brand_name');
        }
        if ($values['zarinpal_merchant_id'] === '') {
            $values['zarinpal_merchant_id'] = trim((string) config('repair.zarinpal_merchant_id', ''));
        }

        return $values;
    }

    public static function locationMode(?array $values = null): string
    {
        $mode = $values['location_mode'] ?? self::value('location_mode');

        return in_array($mode, ['off', 'optional', 'required'], true) ? $mode : 'optional';
    }

    /** سرویس نقشهٔ نهایی: neshan یا osm (نشان بدون کلید ممکن نیست). */
    public static function mapProvider(?array $values = null): string
    {
        $choice = $values['map_provider'] ?? self::value('map_provider');
        if ($choice === 'osm' || (string) config('repair.neshan_map_key') === '') {
            return 'osm';
        }

        return 'neshan';
    }

    public static function value(string $key): string
    {
        $row = self::query()->where('key', $key)->value('value');
        if ($row !== null) {
            return (string) $row;
        }
        if ($key === 'zarinpal_merchant_id') {
            return trim((string) config('repair.zarinpal_merchant_id', ''));
        }

        return (string) (self::DEFAULTS[$key] ?? '');
    }

    public static function put(string $key, ?string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** مرچنت زرین‌پال تعمیرات؛ null یعنی از مرچنت مرکزی وبینو استفاده شود. */
    public static function zarinpalMerchantId(): ?string
    {
        $merchant = trim(self::value('zarinpal_merchant_id'));

        return $merchant !== '' ? $merchant : null;
    }

    public static function onlineGatewayAvailable(): bool
    {
        if (self::value('online_payment_enabled') !== '1') {
            return false;
        }

        return self::zarinpalMerchantId() !== null
            || trim((string) config('zarinpal.merchant_id')) !== '';
    }

    /**
     * @return list<string>
     */
    public static function categories(?array $values = null): array
    {
        $raw = $values['categories'] ?? self::value('categories');

        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $raw) ?: [])));
    }
}
