<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketerPayout extends Model
{
    protected $fillable = ['marketer_id', 'amount_toman', 'note', 'paid_at', 'created_by_user_id'];

    protected $casts = [
        'amount_toman' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }
}
