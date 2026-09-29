<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * اتصال یک حساب حسابرس به یک فروشگاه (فقط مشاهده).
 * permissions = null یعنی همهٔ بخش‌ها.
 */
class ShopAuditor extends Model
{
    protected $fillable = [
        'atelier_id',
        'user_id',
        'name',
        'is_active',
        'permissions',
        'note',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'permissions' => 'array',
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
