<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    use HasFactory , QueryTools;


    protected $fillable = ["name", "code", "state_id"];


    public function State(): \Illuminate\Database\Eloquent\Relations\BelongsTo {
        return $this->belongsTo(State::class);
    }
}
