<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserShiksho extends Model
{
    use HasFactory;

    protected $table = 'user_shiksho';

    protected $fillable = [
        'phone',
        'atelier_id',
        'credit',
        'installment_credit',
        'credit_last_updated_at',
        'last_warning_sent_at'
    ];

    protected $casts = [
        'credit' => 'decimal:2',
        'installment_credit' => 'decimal:2',
        'credit_last_updated_at' => 'datetime',
        'last_warning_sent_at' => 'datetime'
    ];

    /**
     * محاسبه اعتبار بر اساس مبلغ خرید
     * اعتبار رند می‌شود به طوری که سه رقم آخر 0 باشد
     * 
     * @param float $purchaseAmount
     * @return float
     */
    public static function calculateCredit($purchaseAmount)
    {
        $credit = 0;
        
        if ($purchaseAmount <= 1000000) {
            // تا 1 میلیون: 5 درصد
            $credit = $purchaseAmount * 0.01;
        } elseif ($purchaseAmount <= 2000000) {
            // تا 2 میلیون: 4 درصد
            $credit = $purchaseAmount * 0.01;
        } else {
            // بالاتر از 2 میلیون: 3 درصد
            $credit = $purchaseAmount * 0.02;
        }

        // رند کردن به نزدیک‌ترین عدد با سه رقم آخر 0
        $credit = round($credit / 1000) * 1000;

        return $credit;
    }

    /**
     * افزودن یا به‌روزرسانی اعتبار کاربر
     * اعتبار قبلی صفر می‌شود و اعتبار جدید اضافه می‌شود
     *
     * @param  int|null  $atelierId  اگر null باشد، فقط ردیف‌های legacy با atelier_id خالی.
     */
    public static function updateCredit($phone, $creditAmount, ?int $atelierId = null)
    {
        $q = static::query()->where('phone', $phone);
        if ($atelierId !== null) {
            $q->where('atelier_id', $atelierId);
        } else {
            $q->whereNull('atelier_id');
        }
        $user = $q->first();

        if (! $user) {
            $user = new static([
                'phone' => $phone,
                'credit' => 0,
                'credit_last_updated_at' => now(),
            ]);
            if ($atelierId !== null) {
                $user->atelier_id = $atelierId;
            }
            $user->save();
        }

        // اعتبار قبلی صفر می‌شود و اعتبار جدید اضافه می‌شود
        $user->credit = $creditAmount;
        $user->credit_last_updated_at = now();
        $user->last_warning_sent_at = null; // ریست کردن هشدار برای اعتبار جدید
        $user->save();

        return $user;
    }

    /**
     * استفاده از اعتبار (کاهش اعتبار)
     * توجه: استفاده از اعتبار، تاریخ credit_last_updated_at را تغییر نمی‌دهد
     * 
     * @param float $amount
     * @return bool
     */
    public function useCredit($amount)
    {
        if ($this->credit >= $amount) {
            $this->credit -= $amount;
            // credit_last_updated_at را تغییر نمی‌دهیم چون فقط استفاده شده، نه اعتبار جدید
            $this->save();
            return true;
        }
        return false;
    }

    /**
     * استفاده از اعتبار اقساطی (کاهش اعتبار اقساطی)
     * 
     * @param float $amount
     * @return bool
     */
    public function useInstallmentCredit($amount)
    {
        if ($this->installment_credit >= $amount) {
            $this->installment_credit -= $amount;
            $this->save();
            return true;
        }
        return false;
    }

    /**
     * افزودن به اعتبار اقساطی
     * 
     * @param float $amount
     * @return void
     */
    public function addInstallmentCredit($amount)
    {
        $this->installment_credit += $amount;
        $this->save();
    }
}

