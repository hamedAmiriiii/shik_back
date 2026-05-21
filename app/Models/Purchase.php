<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'phone',
        'total_amount',
        'credit_used',
        'credit_earned',
        'payment_type',
        'card_amount',
        'cash_amount',
        'installment_count',
        'installment_amount',
        'atelier_id',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'credit_used' => 'decimal:2',
        'credit_earned' => 'decimal:2',
        'card_amount' => 'decimal:2',
        'cash_amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
    ];

    public function getCreatedAtAttribute($value): string
    {
        if (!$value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');
        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }

    /**
     * محصولات این خرید
     */
    public function purchasedProducts()
    {
        return $this->hasMany(PurchasedProduct::class);
    }

    /**
     * خریدهای متعلق به یک فروشگاه (ستون atelier_id یا استنباط از محصولات خرید).
     */
    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where(function ($q) use ($atelierId) {
            $q->where('atelier_id', $atelierId)
                ->orWhere(function ($q2) use ($atelierId) {
                    $q2->whereNull('atelier_id')
                        ->whereHas('purchasedProducts.product', function ($p) use ($atelierId) {
                            $p->where('atelier_id', $atelierId);
                        });
                });
        });
    }

    /**
     * سبد خرید این خرید (اگر سفارش اینترنتی باشد)
     */
    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * قسط‌های این خرید
     */
    public function installments()
    {
        return $this->hasMany(Installment::class)->orderBy('installment_number');
    }

    /**
     * قسط‌های پرداخت شده
     */
    public function paidInstallments()
    {
        return $this->hasMany(Installment::class)->where('is_paid', true);
    }

    /**
     * قسط‌های پرداخت نشده
     */
    public function unpaidInstallments()
    {
        return $this->hasMany(Installment::class)->where('is_paid', false);
    }

    /**
     * بررسی اینکه آیا خرید اقساطی است
     */
    public function isInstallment()
    {
        return $this->payment_type === 'installment';
    }

    /**
     * محاسبه مبلغ پرداخت شده از قسط‌ها
     */
    public function getPaidAmountAttribute()
    {
        // اگر installments قبلاً load شده باشد، از آن استفاده می‌کنیم
        if ($this->relationLoaded('installments')) {
            return $this->installments->where('is_paid', true)->sum('amount');
        }
        // در غیر این صورت query می‌زنیم
        return $this->paidInstallments()->sum('amount');
    }

    /**
     * محاسبه مبلغ باقیمانده
     */
    public function getRemainingAmountAttribute()
    {
        return $this->total_amount - $this->paid_amount;
    }

    /**
     * دریافت مبلغ واقعی پرداخت شده
     * برای خریدهای اقساطی: مجموع قسط‌های پرداخت شده
     * برای خریدهای نقدی: مبلغ کل خرید
     */
    public function getActualPaidAmountAttribute()
    {
        if ($this->isInstallment()) {
            return $this->paid_amount;
        }
        return $this->total_amount;
    }
}

