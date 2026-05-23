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

    /**
     * جمع فروش خطوط باقی‌مانده روی فاکتور.
     */
    public function remainingLineSalesTotal(): float
    {
        if (! $this->relationLoaded('purchasedProducts')) {
            $this->load('purchasedProducts');
        }

        return round((float) $this->purchasedProducts->sum(function ($pp) {
            return (float) $pp->sale_price * (int) $pp->quantity;
        }), 2);
    }

    /**
     * جمع بهای تمام‌شده خطوط باقی‌مانده.
     */
    public function remainingLinePurchaseCost(): float
    {
        if (! $this->relationLoaded('purchasedProducts')) {
            $this->load('purchasedProducts');
        }

        return round((float) $this->purchasedProducts->sum(function ($pp) {
            return (float) $pp->purchase_price * (int) $pp->quantity;
        }), 2);
    }

    /**
     * همگام‌سازی مبالغ فاکتور با اقلام باقی‌مانده (بعد از برگشت).
     */
    public function syncAmountsFromRemainingLines(): void
    {
        $lineTotal = $this->remainingLineSalesTotal();

        if ($lineTotal <= 0) {
            $this->total_amount = 0;
            $this->card_amount = 0;
            $this->cash_amount = 0;
            $this->credit_used = 0;
            $this->credit_earned = 0;

            return;
        }

        if ($this->isInstallment()) {
            $this->total_amount = $lineTotal;

            return;
        }

        $creditUsed = min((float) $this->credit_used, $lineTotal);
        $this->credit_used = $creditUsed;
        $payable = round($lineTotal - $creditUsed, 2);
        $this->total_amount = $payable;

        $card = (float) $this->card_amount;
        $cash = (float) $this->cash_amount;
        $settlement = $card + $cash;

        if ($settlement > 0 && abs($settlement - $payable) > 0.02) {
            $ratio = $payable / $settlement;
            $this->card_amount = round($card * $ratio, 2);
            $this->cash_amount = round($cash * $ratio, 2);
            $fix = round($payable - ((float) $this->card_amount + (float) $this->cash_amount), 2);
            if (abs($fix) >= 0.01) {
                $this->cash_amount = round((float) $this->cash_amount + $fix, 2);
            }
        } elseif ($settlement <= 0 && $payable > 0) {
            $this->cash_amount = $payable;
            $this->card_amount = 0;
        }
    }
}

