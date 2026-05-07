<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'phone',
        'password',
        'name',
        'last_name',
        'national_code',
        'state_id',
        'city_id',
        'address',
        'postal_code',
        'is_verified'
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    /**
     * استان مشتری
     */
    public function state()
    {
        return $this->belongsTo(State::class);
    }

    /**
     * شهر مشتری
     */
    public function city()
    {
        return $this->belongsTo(City::class);
    }

    /**
     * خریدهای این مشتری
     */
    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'phone', 'phone');
    }

    /**
     * سبدهای خرید این مشتری
     */
    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    /**
     * سبد خرید فعلی (pending) این مشتری
     */
    public function currentCart()
    {
        return $this->hasOne(Cart::class)->where('status', 'pending');
    }

    /**
     * آدرس‌های این مشتری
     */
    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * آدرس پیش‌فرض این مشتری
     */
    public function defaultAddress()
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default', true);
    }
}

