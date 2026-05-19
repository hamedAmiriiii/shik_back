<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class ReturnedProduct extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'sale_price'];

    protected $casts = [
        'sale_price' => 'decimal:2',
    ];

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

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * برگشتی‌های متعلق به یک فروشگاه.
     */
    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where(function ($q) use ($atelierId) {
            $q->where('atelier_id', $atelierId)
                ->orWhere(function ($q2) use ($atelierId) {
                    $q2->whereNull('atelier_id')
                        ->whereHas('product', function ($p) use ($atelierId) {
                            $p->where('atelier_id', $atelierId);
                        });
                });
        });
    }
}

