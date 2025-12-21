<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'total_amount',
        'credit_used',
        'credit_earned'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'credit_used' => 'decimal:2',
        'credit_earned' => 'decimal:2',
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
}

