<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoadianApiLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'atelier_id',
        'action',
        'method',
        'url',
        'http_status',
        'duration_ms',
        'document_ids',
        'request',
        'response',
        'error',
        'created_at',
    ];

    protected $casts = [
        'document_ids' => 'array',
        'created_at' => 'datetime',
    ];
}
