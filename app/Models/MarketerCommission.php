<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سهم بازاریاب از یک پرداخت پلن (درصد در لحظهٔ ثبت ذخیره می‌شود تا با تغییر تنظیمات عوض نشود).
 */
class MarketerCommission extends Model
{
    protected $fillable = [
        'marketer_id',
        'marketer_referral_id',
        'atelier_id',
        'gateway_payment_id',
        'purchase_amount_toman',
        'percent',
        'commission_toman',
        'description',
        'purchased_at',
    ];

    protected $casts = [
        'purchase_amount_toman' => 'integer',
        'percent' => 'decimal:2',
        'commission_toman' => 'integer',
        'purchased_at' => 'datetime',
    ];

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(MarketerReferral::class, 'marketer_referral_id');
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }
}
