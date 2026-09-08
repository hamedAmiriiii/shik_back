<?php

namespace App\Models;

use App\Services\ShopLoyaltyCreditTierService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'atelier_id'];

    /** @var int|null فقط برای درخواست HTTP فعلی (از کنترلر ست می‌شود) */
    protected static $contextAtelierId;

    public static function setContextAtelierId(?int $atelierId): void
    {
        static::$contextAtelierId = $atelierId;
    }

    /**
     * ست کردن زمینهٔ فروشگاه برای درخواست + اطمینان از وجود رکوردهای پیش‌فرض.
     */
    public static function setShopContext(?int $atelierId): void
    {
        static::setContextAtelierId($atelierId);
        if ($atelierId !== null) {
            static::ensureDefaultsForAtelier($atelierId);
        }
    }

    /**
     * ایجاد رکوردهای پیش‌فرض تنظیمات برای یک فروشگاه (در صورت نبود).
     * برای بار اول که هنوز ردیفی در settings برای آن atelier_id نیست.
     */
    public static function ensureDefaultsForAtelier(int $atelierId): void
    {
        if ($atelierId <= 0) {
            return;
        }

        $defaults = [
            'enable_loyalty_credit' => '1',
            'credit_expiry_days' => '60',
            'installment_monthly_interest_rate' => '0',
            'shop_sms_quota' => '0',
            'salary_hourly_wage' => '0',
            'salary_monthly_work_hours' => '220',
            'shop_card_number' => '',
            'shop_card_holder' => '',
            'shop_bank_name' => '',
            'room_services_enabled' => '0',
        ];

        foreach ($defaults as $key => $value) {
            try {
                static::query()->firstOrCreate(
                    ['key' => $key, 'atelier_id' => $atelierId],
                    ['value' => $value]
                );
            } catch (QueryException $e) {
                // ایندکس قدیمی UNIQUE روی `key` برای فروشگاه دوم insert را رد می‌کند.
                if (! static::isDuplicateKeyException($e)) {
                    throw $e;
                }
            }
        }

        ShopLoyaltyCreditTierService::ensureDefaultsForAtelier($atelierId);
    }

    /**
     * دریافت مقدار یک setting
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $q = static::query()->where('key', $key);
        if (static::$contextAtelierId !== null) {
            $q->where('atelier_id', static::$contextAtelierId);
        } else {
            $q->whereNull('atelier_id');
        }
        $setting = $q->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * تنظیم مقدار یک setting
     *
     * @param string $key
     * @param mixed $value
     * @return Setting
     */
    public static function set($key, $value)
    {
        $attrs = ['key' => $key];
        if (static::$contextAtelierId !== null) {
            $attrs['atelier_id'] = static::$contextAtelierId;
        } else {
            $attrs['atelier_id'] = null;
        }

        try {
            return self::updateOrCreate($attrs, ['value' => $value]);
        } catch (QueryException $e) {
            if (! static::isDuplicateKeyException($e)) {
                throw $e;
            }

            $row = static::query()
                ->where('key', $key)
                ->when(
                    $attrs['atelier_id'] !== null,
                    fn ($q) => $q->where('atelier_id', $attrs['atelier_id']),
                    fn ($q) => $q->whereNull('atelier_id')
                )
                ->first();

            if (! $row) {
                $row = static::query()->where('key', $key)->first();
            }

            if (! $row) {
                throw $e;
            }

            $row->value = $value;
            $row->save();

            return $row;
        }
    }

    private static function isDuplicateKeyException(QueryException $e): bool
    {
        return $e->getCode() === '23000' || strpos($e->getMessage(), 'Duplicate entry') !== false;
    }

    /**
     * بررسی اینکه آیا یک setting فعال است یا نه (boolean)
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public static function isEnabled($key, $default = false)
    {
        $value = self::get($key, $default ? '1' : '0');

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on']);
    }
}
