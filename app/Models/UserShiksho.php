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
        'credit'
    ];

    protected $casts = [
        'credit' => 'decimal:2'
    ];

    /**
     * محاسبه اعتبار بر اساس مبلغ خرید
     * 
     * @param float $purchaseAmount
     * @return float
     */
    public static function calculateCredit($purchaseAmount)
    {
        if ($purchaseAmount <= 1000000) {
            // تا 1 میلیون: 5 درصد
            return $purchaseAmount * 0.05;
        } elseif ($purchaseAmount <= 2000000) {
            // تا 2 میلیون: 4 درصد
            return $purchaseAmount * 0.04;
        } else {
            // بالاتر از 2 میلیون: 3 درصد
            return $purchaseAmount * 0.03;
        }
    }

    /**
     * افزودن یا به‌روزرسانی اعتبار کاربر
     * اعتبار قبلی صفر می‌شود و اعتبار جدید اضافه می‌شود
     * 
     * @param string $phone
     * @param float $creditAmount
     * @return UserShiksho
     */
    public static function updateCredit($phone, $creditAmount)
    {
        $user = self::firstOrCreate(
            ['phone' => $phone],
            ['credit' => 0]
        );

        // اعتبار قبلی صفر می‌شود و اعتبار جدید اضافه می‌شود
        $user->credit = $creditAmount;
        $user->save();

        return $user;
    }

    /**
     * استفاده از اعتبار (کاهش اعتبار)
     * 
     * @param float $amount
     * @return bool
     */
    public function useCredit($amount)
    {
        if ($this->credit >= $amount) {
            $this->credit -= $amount;
            $this->save();
            return true;
        }
        return false;
    }
}

