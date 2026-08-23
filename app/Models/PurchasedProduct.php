<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Morilog\Jalali\Jalalian;

class PurchasedProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'product_id',
        'produced_good_id',
        'raw_material_id',
        'item_name',
        'quantity',
        'purchase_price',
        'sale_price',
        'size',
        'color',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'quantity' => 'decimal:3',
    ];

    protected $appends = ['item_type', 'display_name'];

    public function getCreatedAtAttribute($value): string
    {
        if (!$value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');
        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }

    public function getItemTypeAttribute(): string
    {
        if ($this->produced_good_id) {
            return 'produced_good';
        }
        if ($this->raw_material_id) {
            return 'raw_material';
        }

        return 'product';
    }

    public function getDisplayNameAttribute(): ?string
    {
        if ($this->item_name) {
            return $this->item_name;
        }
        if ($this->producedGood) {
            return $this->producedGood->name;
        }
        if ($this->rawMaterial) {
            return $this->rawMaterial->name;
        }

        return optional($this->product)->name;
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function producedGood()
    {
        return $this->belongsTo(ProducedGood::class);
    }

    public function rawMaterial()
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function stockConsumptions(): HasMany
    {
        return $this->hasMany(PurchaseStockConsumption::class);
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }
}
