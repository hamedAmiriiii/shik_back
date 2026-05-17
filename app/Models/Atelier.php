<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Atelier extends Model
{
    use HasFactory, QueryTools;

    protected static function boot()
    {
        parent::boot();

        static::created(function (Atelier $atelier) {
            Setting::ensureDefaultsForAtelier((int) $atelier->id);
        });
    }


    protected $fillable = [
        'name',
        'code',
        'address',
        'business_license',
        'shop_access_starts_at',
        'shop_access_ends_at',
        'shop_access_suspended',
    ];

    protected $casts = [
        'shop_access_starts_at' => 'datetime',
        'shop_access_ends_at' => 'datetime',
        'shop_access_suspended' => 'boolean',
    ];

    /**
     * آیا پرسنل متصل به این فروشگاه اجازهٔ ورود و کار با بخش فروشگاه را دارد؟
     */
    public function isShopAccessActive(): bool
    {
        if ($this->shop_access_suspended) {
            return false;
        }
        $now = now();
        if ($this->shop_access_starts_at && $now->lt($this->shop_access_starts_at)) {
            return false;
        }
        if ($this->shop_access_ends_at && $now->gt($this->shop_access_ends_at)) {
            return false;
        }

        return true;
    }

    public function staffUsers()
    {
        return $this->hasMany(User::class, 'atelier_id');
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }


    public function getBusinessLicenseAttribute($value): string
    {
        return Storage::url($value);
    }
}
