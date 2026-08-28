<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Cheque;
use Morilog\Jalali\Jalalian;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount', 'title', 'description', 'date', 'user_name', 'atelier_id', 'shop_account_id',
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
     * حسابی (فروشگاه یا تنخواه) که مبلغ از آن پرداخت شده است.
     */
    public function shopAccount()
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }

    public function cheque()
    {
        return $this->belongsTo(Cheque::class, 'cheque_id');
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        $method = $this->attributes['payment_method'] ?? 'account';
        if ($method === 'cheque') {
            return 'چکی';
        }
        if ($method === 'credit') {
            return 'نسیه';
        }

        return 'از حساب';
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return (($this->attributes['payment_status'] ?? 'paid') === 'unpaid') ? 'پرداخت‌نشده' : 'پرداخت‌شده';
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

