<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class InstallmentPayment extends Model
{
    protected $fillable = [
        'purchase_id',
        'installment_id',
        'amount',
        'notes',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected $appends = [
        'paid_at_jalali',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function installment()
    {
        return $this->belongsTo(Installment::class);
    }

    public function getPaidAtJalaliAttribute(): ?string
    {
        $paidAt = $this->getRawOriginal('paid_at') ?: $this->paid_at;
        if (! $paidAt) {
            return null;
        }

        return Jalalian::fromCarbon(\Carbon\Carbon::parse($paidAt)->setTimezone('Asia/Tehran'))
            ->format('Y-m-d H:i:s');
    }
}
