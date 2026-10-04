<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairSmsLog extends Model
{
    public const TYPE_OTP = 'otp';

    public const TYPE_REQUEST = 'request';

    public const TYPE_ASSIGNED = 'assigned';

    public const TYPE_INVOICED = 'invoiced';

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_COMPLETED = 'completed';

    public const TYPE_CANCELED = 'canceled';

    public const TYPE_TECHNICIAN = 'technician';

    public const TYPE_LABELS = [
        self::TYPE_OTP => 'کد ورود',
        self::TYPE_REQUEST => 'درخواست جدید',
        self::TYPE_ASSIGNED => 'ارجاع به تعمیرکار',
        self::TYPE_INVOICED => 'صدور هزینه',
        self::TYPE_RECEIPT => 'رسید پرداخت',
        self::TYPE_COMPLETED => 'پرداخت و پایان',
        self::TYPE_CANCELED => 'لغو درخواست',
        self::TYPE_TECHNICIAN => 'ثبت‌نام تعمیرکار',
    ];

    /** ارسال نشد چون اعتبار پیامک کافی نبود */
    public const STATUS_NO_CREDIT = 'NO_CREDIT';

    protected $table = 'repair_sms_logs';

    protected $fillable = [
        'phone',
        'message',
        'sms_type',
        'sms_parts',
        'batch_id',
        'reference_id',
        'delivery_status',
        'provider_datetime',
        'status_checked_at',
    ];

    protected $casts = [
        'sms_parts' => 'integer',
        'status_checked_at' => 'datetime',
    ];

    public static function statusLabel(?string $status): string
    {
        if ($status === self::STATUS_NO_CREDIT) {
            return 'اعتبار ناکافی';
        }

        return ShopSmsLog::STATUS_LABELS[$status ?: ShopSmsLog::STATUS_UNKNOWN] ?? (string) $status;
    }

    public function isFinalStatus(): bool
    {
        return $this->delivery_status === self::STATUS_NO_CREDIT
            || in_array($this->delivery_status, ShopSmsLog::FINAL_STATUSES, true);
    }

    public function canRefreshStatus(): bool
    {
        return ! $this->isFinalStatus() && ((string) $this->reference_id !== '' || (string) $this->batch_id !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'message' => $this->message,
            'sms_type' => $this->sms_type,
            'sms_type_label' => self::TYPE_LABELS[$this->sms_type] ?? 'اطلاع‌رسانی',
            'sms_parts' => (int) $this->sms_parts,
            'delivery_status' => $this->delivery_status ?: ShopSmsLog::STATUS_UNKNOWN,
            'delivery_status_label' => self::statusLabel($this->delivery_status),
            'can_refresh' => $this->canRefreshStatus(),
            'created_at' => $this->created_at?->toIso8601String(),
            'status_checked_at' => $this->status_checked_at?->toIso8601String(),
        ];
    }
}
