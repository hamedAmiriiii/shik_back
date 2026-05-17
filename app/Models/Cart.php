<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'customer_id',
        'atelier_id',
        'address_id',
        'status',
        'shipping_name',
        'shipping_last_name',
        'shipping_phone',
        'shipping_address',
        'shipping_state_id',
        'shipping_state_name',
        'shipping_city_id',
        'shipping_city_name',
        'shipping_postal_code'
    ];

    protected $casts = [
        'customer_id' => 'integer',
    ];

    protected $appends = ['total', 'items_count'];

    /**
     * مشتری این سبد
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * آدرس ارسال این سبد
     */
    public function address()
    {
        return $this->belongsTo(CustomerAddress::class, 'address_id');
    }

    /**
     * آیتم‌های این سبد
     */
    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * محاسبه مجموع مبلغ سبد
     */
    public function getTotalAttribute()
    {
        return $this->items->sum(function ($item) {
            return $item->quantity * $item->price;
        });
    }

    /**
     * محاسبه تعداد کل آیتم‌ها
     */
    public function getItemsCountAttribute()
    {
        return $this->items->sum('quantity');
    }
}

