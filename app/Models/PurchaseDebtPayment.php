<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class PurchaseDebtPayment extends Model
{
    protected $fillable = [
        'purchase_id',
        'card_amount',
        'cash_amount',
        'note',
        'paid_at',
    ];

    protected $casts = [
        'card_amount' => 'decimal:2',
        'cash_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected $appends = [
        'amount',
        'paid_at_jalali',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function getAmountAttribute(): float
    {
        return round((float) $this->card_amount + (float) $this->cash_amount, 2);
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
