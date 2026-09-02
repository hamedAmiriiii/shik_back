<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OilVisitItem extends Model
{
    protected $fillable = [
        'oil_visit_id',
        'oil_product_id',
        'kind',
        'product_name',
        'purchase_price',
        'sale_price',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(OilVisit::class, 'oil_visit_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(OilProduct::class, 'oil_product_id');
    }

    public function toApiArray(): array
    {
        return [
            'kind' => $this->kind,
            'kind_label' => OilProduct::kindLabel((string) $this->kind),
            'oil_product_id' => $this->oil_product_id ? (int) $this->oil_product_id : null,
            'name' => $this->product_name,
            'purchase_price' => round((float) ($this->purchase_price ?? 0), 2),
            'sale_price' => round((float) ($this->sale_price ?? 0), 2),
        ];
    }
}
