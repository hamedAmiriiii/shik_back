<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoadianSaleLink extends Model
{
    protected $fillable = [
        'atelier_id',
        'purchase_id',
        'head_document_id',
        'fingerprint',
        'closed',
        'checked_at',
    ];

    protected $casts = [
        'closed' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public function head()
    {
        return $this->belongsTo(MoadianDocument::class, 'head_document_id');
    }
}
