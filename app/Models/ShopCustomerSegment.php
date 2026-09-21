<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopCustomerSegment extends Model
{
    public const VIP = 'vip';
    public const AT_RISK = 'at_risk';
    public const CHURNED = 'churned';
    public const INACTIVE = 'inactive';
    public const LOYAL = 'loyal';
    public const GROWING = 'growing';
    public const NEW = 'new';
    public const OTHER = 'other';

    public const TAG_HIGH_VALUE = 'high_value';
    public const TAG_LOW_VALUE = 'low_value';
    public const TAG_NEAR_VIP = 'near_vip';
    public const TAG_READY_REPURCHASE = 'ready_repurchase';

    protected $table = 'shop_customer_segments';

    protected $fillable = [
        'atelier_id',
        'phone',
        'primary_segment',
        'tags',
        'rfm_scores',
    ];

    protected $casts = [
        'tags' => 'array',
        'rfm_scores' => 'array',
    ];

    public static function labels(): array
    {
        return [
            self::VIP => 'ویژه',
            self::AT_RISK => 'نزدیک به رفتن',
            self::CHURNED => 'دیگه نمیاد',
            self::INACTIVE => 'مدتی نخریده',
            self::LOYAL => 'همیشگی',
            self::GROWING => 'رو به رشد',
            self::NEW => 'تازه‌وارد',
            self::OTHER => 'بقیه',
        ];
    }
}
