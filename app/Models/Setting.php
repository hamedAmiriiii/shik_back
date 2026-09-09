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
            'restaurant_cafe_enabled' => '0',
            'produced_goods_enabled' => '0',
            'accounting_enabled' => '0',
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
        $atelierId = static::$contextAtelierId;
        $rowQuery = static::query()->where('key', $key);
        if ($atelierId !== null) {
            $rowQuery->where('atelier_id', $atelierId);
        } else {
            $rowQuery->whereNull('atelier_id');
        }

        $row = $rowQuery->first();
        if ($row) {
            $row->value = $value;
            $row->save();

            return $row;
        }

        try {
            return static::query()->create([
                'key' => $key,
                'value' => $value,
                'atelier_id' => $atelierId,
            ]);
        } catch (QueryException $e) {
            if (! static::isDuplicateKeyException($e)) {
                throw $e;
            }

            $retry = $rowQuery->first();
            if ($retry) {
                $retry->value = $value;
                $retry->save();

                return $retry;
            }

            throw $e;
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
