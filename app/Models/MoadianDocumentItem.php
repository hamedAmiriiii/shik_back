<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoadianDocumentItem extends Model
{
    protected $fillable = [
        'moadian_document_id',
        'line_key',
        'purchased_product_id',
        'sstid',
        'sstt',
        'mu',
        'am',
        'fee',
        'prdis',
        'dis',
        'adis',
        'vra',
        'vam',
        'odr',
        'odam',
        'tsstam',
    ];

    protected $casts = [
        'am' => 'float',
        'fee' => 'float',
        'prdis' => 'float',
        'dis' => 'float',
        'adis' => 'float',
        'vra' => 'float',
        'vam' => 'float',
        'odr' => 'float',
        'odam' => 'float',
        'tsstam' => 'float',
    ];

    public function toApiArray(): array
    {
        return [
            'line_key' => $this->line_key,
            'sstid' => $this->sstid,
            'sstt' => $this->sstt,
            'am' => $this->am,
            'fee' => $this->fee,
            'dis' => $this->dis,
            'adis' => $this->adis,
            'vra' => $this->vra,
            'vam' => $this->vam,
            'odam' => $this->odam,
            'tsstam' => $this->tsstam,
        ];
    }
}
