<?php

namespace App\Models;

use App\Services\ShopLoyaltyCreditTierService;
use App\Tools\PriceTools;
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
     * @param  float  $purchaseAmount
     * @param  int|null  $atelierId  فروشگاه — بازه‌های اعتبار از تنظیمات همان فروشگاه
     */
    public static function calculateCredit($purchaseAmount, ?int $atelierId = null): float
    {
        return ShopLoyaltyCreditTierService::calculateCredit((float) $purchaseAmount, $atelierId);
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

        // اعتبار قبلی صفر می‌شود و اعتبار جدید اضافه می‌شود (رند به هزار)
        $user->credit = PriceTools::roundToThousand((float) $creditAmount);
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
        $this->installment_credit = PriceTools::roundToThousand((float) $this->installment_credit + (float) $amount);
        $this->save();
    }
}

