<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Cheque;
use Morilog\Jalali\Jalalian;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_name', 'date', 'amount', 'title', 'type', 'atelier_id', 'beneficiary_id', 'shop_account_id',
        'payment_method', 'payment_status', 'paid_at', 'cheque_id',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected $appends = [
        'payment_method_label',
        'payment_status_label',
    ];

    /**
     * حسابی (فروشگاه یا تنخواه) که مبلغ از آن برداشت شده است.
     */
    public function shopAccount()
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }

    /**
     * کاربری که از او خرید شده (باشگاه مشتریان همین فروشگاه).
     */
    public function beneficiary()
    {
        return $this->belongsTo(UserShiksho::class, 'beneficiary_id');
    }

    public function cheque()
    {
        return $this->belongsTo(Cheque::class, 'cheque_id');
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        $method = $this->attributes['payment_method'] ?? 'account';
        if ($method === 'mixed' && $this->relationLoaded('payments')) {
            return \App\Services\DocumentPaymentService::mixedLabelFromMethods(
                $this->payments->pluck('method')->all()
            );
        }

        return \App\Services\DocumentPaymentService::methodLabel((string) $method);
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return \App\Services\DocumentPaymentService::statusLabel($this->attributes['payment_status'] ?? 'paid');
    }

    public function payments()
    {
        return $this->hasMany(DocumentPayment::class)->orderBy('sort_order')->orderBy('id');
    }

    public function cheques()
    {
        return $this->hasMany(Cheque::class);
    }

    public function getDateAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function getCreatedAtAttribute($value): string
    {
        if (!$value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');
        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }

    public function getUpdatedAtAttribute($value): string
    {
        if (!$value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');
        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }
}

