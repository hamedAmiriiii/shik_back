<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsPackageOrder extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'atelier_id',
        'sms_package_id',
        'sms_count',
        'price_rial',
        'status',
        'requested_by_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'admin_note',
    ];

    protected $casts = [
        'sms_count' => 'integer',
        'price_rial' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function smsPackage(): BelongsTo
    {
        return $this->belongsTo(SmsPackage::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
