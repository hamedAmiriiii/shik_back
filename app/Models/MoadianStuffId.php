<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoadianStuffId extends Model
{
    protected $fillable = [
        'atelier_id',
        'sstid',
        'title',
        'vat_rate',
        'other_tax_rate',
        'other_tax_subject',
        'unit_code',
    ];

    protected $casts = [
        'vat_rate' => 'float',
        'other_tax_rate' => 'float',
    ];
}
