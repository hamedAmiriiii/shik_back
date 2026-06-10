<?php

namespace App\Models;

use App\Services\ShopLoyaltyCreditTierService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        ];

        foreach ($defaults as $key => $value) {
            static::query()->firstOrCreate(
                ['key' => $key, 'atelier_id' => $atelierId],
                ['value' => $value]
            );
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

        return self::updateOrCreate($attrs, ['value' => $value]);
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
