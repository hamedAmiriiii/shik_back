<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class Installment extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'installment_number',
        'amount',
        'due_date',
        'is_paid',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'is_paid' => 'boolean',
        'paid_at' => 'datetime',
    ];

    protected $appends = [
        'due_date_jalali',
        'paid_at_jalali',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * تبدیل تاریخ سررسید به شمسی
     */
    public function getDueDateJalaliAttribute()
    {
        return \App\Tools\JalaliTools::format($this->attributes['due_date'] ?? null, 'Y/m/d', null);
    }

    /**
     * تبدیل تاریخ پرداخت به شمسی
     */
    public function getPaidAtJalaliAttribute()
    {
        return \App\Tools\JalaliTools::format($this->attributes['paid_at'] ?? null, 'Y/m/d H:i:s', null);
    }

    /**
     * بررسی اینکه آیا قسط سررسید شده است
     */
    public function isOverdue()
    {
        return !$this->is_paid && $this->due_date < now()->toDateString();
    }

    /**
     * بررسی اینکه آیا قسط در 3 روز آینده سر می‌رسد
     */
    public function isDueInThreeDays()
    {
        $threeDaysLater = now()->addDays(3)->toDateString();
        return !$this->is_paid && $this->due_date <= $threeDaysLater && $this->due_date >= now()->toDateString();
    }
}

