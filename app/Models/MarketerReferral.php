<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * فروشگاهی که از طریق لینک یک بازاریاب ثبت‌نام کرده است.
 */
class MarketerReferral extends Model
{
    protected $fillable = ['marketer_id', 'atelier_id', 'user_id', 'visitor_id', 'first_visit_at'];

    protected $casts = [
        'first_visit_at' => 'datetime',
    ];

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(MarketerCommission::class);
    }
}
