<?php

namespace App\Models;

use App\Tools\QueryTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LogSms extends Model
{
    use HasFactory,QueryTools;

    protected $fillable = [
        "text", "number", "receivers", "creator_id"
    ];

  
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}