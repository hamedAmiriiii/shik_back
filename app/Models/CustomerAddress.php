<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    use HasFactory;

    protected $table = 'customer_addresses';

    protected $fillable = [
        'customer_id',
        'title',
        'name',
        'last_name',
        'phone',
        'address',
        'state_id',
        'state_name',
        'city_id',
        'city_name',
        'postal_code',
        'is_default'
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'state_id' => 'integer',
        'city_id' => 'integer',
        'is_default' => 'boolean',
    ];

    /**
     * مشتری این آدرس
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * استان این آدرس
     */
    public function state()
    {
        return $this->belongsTo(State::class);
    }

    /**
     * شهر این آدرس
     */
    public function city()
    {
        return $this->belongsTo(City::class);
    }

    /**
     * سبدهای خریدی که از این آدرس استفاده می‌کنند
     */
    public function carts()
    {
        return $this->hasMany(Cart::class, 'address_id');
    }
}
