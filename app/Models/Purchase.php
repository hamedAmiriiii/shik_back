<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Tools\PriceTools;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'phone',
        'total_amount',
        'discount_amount',
        'credit_used',
        'credit_earned',
        'payment_type',
        'cheque_id',
        'card_amount',
        'cash_amount',
        'is_debt_settled',
        'debt_settled_at',
        'debt_settled_card_amount',
        'debt_settled_cash_amount',
        'debt_settlement_note',
        'installment_count',
        'installment_amount',
        'atelier_id',
        'client_id',
        'daily_ticket_number',
        'daily_ticket_date',
        'oil_visit_id',
        'shop_table_id',
        'table_label',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'credit_used' => 'decimal:2',
        'credit_earned' => 'decimal:2',
        'card_amount' => 'decimal:2',
        'cash_amount' => 'decimal:2',
        'is_debt_settled' => 'boolean',
        'debt_settled_at' => 'datetime',
        'debt_settled_card_amount' => 'decimal:2',
        'debt_settled_cash_amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'daily_ticket_number' => 'integer',
        'daily_ticket_date' => 'date',
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

    public function itemReturns()
    {
        return $this->hasMany(PurchaseItemReturn::class);
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
     * میز مربوط به این سفارش (سفارش پای میز)
     */
    public function shopTable()
    {
        return $this->belongsTo(ShopTable::class);
    }

    /**
     * سبد خرید این خرید (اگر سفارش اینترنتی باشد)
     */
    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * چک متصل به فروش چکی
     */
    public function cheque()
    {
        return $this->belongsTo(Cheque::class);
    }

    /**
     * قسط‌های این خرید
     */
    public function installments()
    {
        return $this->hasMany(Installment::class)->orderBy('installment_number');
    }

    /**
     * پرداخت‌های جزئی تسویه نسیه
     */
    public function debtPayments()
    {
        return $this->hasMany(PurchaseDebtPayment::class)->orderBy('paid_at')->orderBy('id');
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

    public function isDebt()
    {
        return $this->payment_type === 'debt';
    }

    public function isCheque()
    {
        return $this->payment_type === 'cheque' || $this->cheque_id !== null;
    }

    /**
     * مبلغ بخش چکی فاکتور (مبلغ چک متصل)
     */
    public function chequeAmount(): float
    {
        if (! $this->isCheque()) {
            return 0.0;
        }

        if ($this->relationLoaded('cheque') && $this->cheque) {
            return round((float) $this->cheque->amount, 2);
        }

        if ($this->cheque_id) {
            $amount = $this->cheque()->value('amount');

            return round((float) $amount, 2);
        }

        return 0.0;
    }

    /**
     * مبلغ نقد/کارت پرداخت‌شده در لحظه فروش (بدون چک)
     */
    public function immediatePaidAmount(): float
    {
        return round((float) $this->card_amount + (float) $this->cash_amount, 2);
    }

    public function isChequeSettled(): bool
    {
        if (! $this->isCheque()) {
            return false;
        }

        if ($this->relationLoaded('cheque')) {
            return $this->cheque && $this->cheque->status === Cheque::STATUS_CLEARED;
        }

        return $this->cheque()->where('status', Cheque::STATUS_CLEARED)->exists();
    }

    public function outstandingChequeAmount(): float
    {
        if (! $this->isCheque() || $this->isChequeSettled()) {
            return 0.0;
        }

        $chequeAmount = $this->chequeAmount();
        if ($chequeAmount > 0) {
            return $chequeAmount;
        }

        return max(0, round($this->payableAmount() - $this->immediatePaidAmount(), 2));
    }

    /**
     * مبلغ قابل پرداخت فاکتور (بعد از تخفیف و اعتبار مصرف‌شده).
     */
    public function payableAmount(): float
    {
        if ($this->isInstallment()) {
            return max(0, round((float) $this->total_amount - (float) $this->credit_used, 2));
        }

        $lineTotal = $this->remainingLineSalesTotal();
        if ($lineTotal <= 0) {
            return 0.0;
        }

        return max(0, round(
            $lineTotal - (float) $this->discount_amount - (float) $this->credit_used,
            2
        ));
    }

    public function isDebtSettled(): bool
    {
        return $this->isDebt() && (bool) $this->is_debt_settled;
    }

    /**
     * مانده نسیه. اگر $asOf داده شود، پرداخت‌های بعد از آن تاریخ کم نمی‌شوند.
     *
     * @param  \Carbon\Carbon|string|null  $asOf
     */
    public function outstandingDebtAmount($asOf = null): float
    {
        if (! $this->isDebt()) {
            return 0.0;
        }

        if ($asOf === null && $this->isDebtSettled()) {
            return 0.0;
        }

        $paid = $this->recordedDebtPaymentsAmount($asOf);

        if ($paid < 0.01 && $this->isDebtSettled()) {
            $settledAt = $this->getRawOriginal('debt_settled_at') ?: $this->debt_settled_at;
            if ($settledAt && $asOf !== null) {
                $cutoff = $asOf instanceof \Carbon\Carbon
                    ? $asOf->copy()
                    : \Carbon\Carbon::parse((string) $asOf);
                if (\Carbon\Carbon::parse($settledAt)->lte($cutoff)) {
                    return 0.0;
                }
            } elseif ($asOf === null) {
                return 0.0;
            }
        }

        return max(0, round(
            $this->payableAmount()
            - $this->immediatePaidAmount()
            - $this->chequeAmount()
            - $paid,
            2
        ));
    }

    /**
     * مجموع پرداخت‌های ثبت‌شده برای تسویه نسیه.
     *
     * @param  \Carbon\Carbon|string|null  $asOf
     */
    public function recordedDebtPaymentsAmount($asOf = null): float
    {
        if (! Schema::hasTable('purchase_debt_payments')) {
            return 0.0;
        }

        $cutoff = null;
        if ($asOf !== null) {
            $cutoff = $asOf instanceof \Carbon\Carbon
                ? $asOf->copy()
                : \Carbon\Carbon::parse((string) $asOf);
        }

        if ($this->relationLoaded('debtPayments')) {
            $rows = $this->debtPayments;
            if ($cutoff) {
                $rows = $rows->filter(function (PurchaseDebtPayment $row) use ($cutoff) {
                    $paidAt = $row->getRawOriginal('paid_at') ?: $row->paid_at;
                    if (! $paidAt) {
                        return false;
                    }

                    return \Carbon\Carbon::parse($paidAt)->lte($cutoff);
                });
            }

            return round((float) $rows->sum(function (PurchaseDebtPayment $row) {
                return (float) $row->card_amount + (float) $row->cash_amount;
            }), 2);
        }

        $query = $this->debtPayments();
        if ($cutoff) {
            $query->where('paid_at', '<=', $cutoff->format('Y-m-d H:i:s'));
        }

        return round((float) $query->get()->sum(function (PurchaseDebtPayment $row) {
            return (float) $row->card_amount + (float) $row->cash_amount;
        }), 2);
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
        if ($this->isDebt()) {
            return $this->outstandingDebtAmount();
        }
        if ($this->isCheque()) {
            return $this->outstandingChequeAmount();
        }

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
        if ($this->isDebt()) {
            if ($this->isDebtSettled()) {
                return $this->payableAmount();
            }

            $paid = $this->immediatePaidAmount();
            if ($this->isChequeSettled()) {
                $paid = round($paid + $this->chequeAmount(), 2);
            }

            return $paid;
        }
        if ($this->isCheque()) {
            $paid = $this->immediatePaidAmount();
            if ($this->isChequeSettled()) {
                $paid = round($paid + $this->chequeAmount(), 2);
            }

            return $paid;
        }

        return max(0, round(
            (float) $this->total_amount - (float) $this->discount_amount - (float) $this->credit_used,
            2
        ));
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
            return (float) $pp->sale_price * (float) $pp->quantity;
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
            return (float) $pp->purchase_price * (float) $pp->quantity;
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

        if ($this->isDebt()) {
            $this->total_amount = $lineTotal;
            if (! $this->isDebtSettled()) {
                $this->card_amount = 0;
                $this->cash_amount = 0;
            }

            return;
        }

        if ($this->isCheque()) {
            $this->total_amount = $lineTotal;
            // نقد/کارت لحظه فروش حفظ می‌شود؛ فقط اگر تسویه نشده و مبلغی ثبت نشده بود صفر می‌کنیم
            if (! $this->isChequeSettled() && $this->immediatePaidAmount() <= 0) {
                $this->card_amount = 0;
                $this->cash_amount = 0;
            }

            return;
        }

        $discount = (float) $this->discount_amount;
        $afterDiscount = max(0, PriceTools::roundToman($lineTotal - $discount));
        $creditUsed = min((float) $this->credit_used, $afterDiscount);
        $this->credit_used = PriceTools::roundToman($creditUsed);
        $payable = PriceTools::roundToman($afterDiscount - $this->credit_used);
        $this->total_amount = $lineTotal;

        $card = (float) $this->card_amount;
        $cash = (float) $this->cash_amount;
        $settlement = $card + $cash;

        if ($settlement > 0 && abs($settlement - $payable) > 0.02) {
            $ratio = $payable / $settlement;
            $this->card_amount = PriceTools::roundToman($card * $ratio);
            $this->cash_amount = PriceTools::roundToman($cash * $ratio);
            $fix = PriceTools::roundToman($payable - ((float) $this->card_amount + (float) $this->cash_amount));
            if (abs($fix) >= 0.01) {
                $this->cash_amount = PriceTools::roundToman((float) $this->cash_amount + $fix);
            }
        } elseif ($settlement <= 0 && $payable > 0) {
            $this->cash_amount = $payable;
            $this->card_amount = 0;
        }
    }
}

