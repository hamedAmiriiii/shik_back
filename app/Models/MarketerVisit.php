<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketerVisit extends Model
{
    protected $fillable = ['marketer_id', 'visitor_id', 'ip', 'user_agent', 'landing_path'];

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }
}
