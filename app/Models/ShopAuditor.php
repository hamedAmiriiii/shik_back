<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * اتصال یک حساب حسابرس به یک فروشگاه (دسترسی کامل در همان فروشگاه).
 */
class ShopAuditor extends Model
{
    protected $fillable = [
        'atelier_id',
        'user_id',
        'name',
        'is_active',
        'note',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
