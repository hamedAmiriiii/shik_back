<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class ShopSmsLog extends Model
{
    use HasFactory;

    public const STATUS_ENQUEUED = 'ENQUEUED';
    public const STATUS_SENT = 'SENT';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_FILTERED = 'FILTERED';
    public const STATUS_BLACKLIST = 'BLACKLIST';
    public const STATUS_UNDELIVERED = 'UNDELIVERED';
    public const STATUS_SCHEDULED = 'SCHEDULED';
    public const STATUS_INVALID_NUMBER = 'INVALID_NUMBER';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_INAPPROPRIATE_CONTENT = 'INAPPROPRIATE_CONTENT';
    public const STATUS_SEND_FAILED = 'SEND_FAILED';
    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const STATUS_LABELS = [
        self::STATUS_ENQUEUED => 'در صف ارسال',
        self::STATUS_SENT => 'ارسال‌شده به مخابرات',
        self::STATUS_DELIVERED => 'تحویل داده شده',
        self::STATUS_FAILED => 'خطا از اپراتور',
        self::STATUS_FILTERED => 'فیلتر شده',
        self::STATUS_BLACKLIST => 'بلک‌لیست',
        self::STATUS_UNDELIVERED => 'تحویل نشده',
        self::STATUS_SCHEDULED => 'زمان‌بندی‌شده',
        self::STATUS_INVALID_NUMBER => 'شماره نامعتبر',
        self::STATUS_PENDING => 'در انتظار تایید',
        self::STATUS_REJECTED => 'رد شده',
        self::STATUS_INAPPROPRIATE_CONTENT => 'متن نامناسب',
        self::STATUS_SEND_FAILED => 'خطا در ارسال',
        self::STATUS_UNKNOWN => 'نامشخص',
    ];

    /** وضعیت‌های نهایی که دیگر نیاز به استعلام ندارند */
    public const FINAL_STATUSES = [
        self::STATUS_DELIVERED,
        self::STATUS_FAILED,
        self::STATUS_FILTERED,
        self::STATUS_BLACKLIST,
        self::STATUS_INVALID_NUMBER,
        self::STATUS_REJECTED,
        self::STATUS_INAPPROPRIATE_CONTENT,
        self::STATUS_SEND_FAILED,
    ];

    protected $table = 'shop_sms_logs';

    protected $fillable = [
        'atelier_id',
        'phone',
        'message',
        'purchase_id',
        'credit_amount',
        'sms_type',
        'batch_id',
        'reference_id',
        'delivery_status',
        'provider_datetime',
        'status_checked_at',
    ];

    protected $casts = [
        'credit_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'status_checked_at' => 'datetime',
    ];

    protected $appends = [
        'delivery_status_label',
    ];

    public function getDeliveryStatusLabelAttribute(): string
    {
        $status = $this->attributes['delivery_status'] ?? null;
        if (! $status) {
            return self::STATUS_LABELS[self::STATUS_UNKNOWN];
        }

        return self::STATUS_LABELS[$status] ?? $status;
    }

    public function isFinalStatus(): bool
    {
        $status = $this->attributes['delivery_status'] ?? null;

        return $status && in_array($status, self::FINAL_STATUSES, true);
    }

    public function canRefreshStatus(): bool
    {
        if ($this->isFinalStatus()) {
            return false;
        }

        $referenceId = $this->attributes['reference_id'] ?? null;
        $batchId = $this->attributes['batch_id'] ?? null;

        return ($referenceId !== null && $referenceId !== '')
            || ($batchId !== null && $batchId !== '');
    }

    /**
     * تبدیل تاریخ به شمسی
     */
    public function getCreatedAtAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');

        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }

    public function getUpdatedAtAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');

        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }

    public function getStatusCheckedAtAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');

        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }
}
