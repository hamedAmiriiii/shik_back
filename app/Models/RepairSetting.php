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
        'default_labor_share_percent' => '70',
        // off: بدون نقشه | optional: اختیاری | required: انتخاب لوکیشن الزامی
        'location_mode' => 'optional',
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

        return $values;
    }

    public static function locationMode(?array $values = null): string
    {
        $mode = $values['location_mode'] ?? self::value('location_mode');

        return in_array($mode, ['off', 'optional', 'required'], true) ? $mode : 'optional';
    }

    public static function value(string $key): string
    {
        $row = self::query()->where('key', $key)->value('value');

        return $row !== null ? (string) $row : (string) (self::DEFAULTS[$key] ?? '');
    }

    public static function put(string $key, ?string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
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
