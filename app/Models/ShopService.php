<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopService extends Model
{
    public const ICONS = [
        'cleaning',
        'blanket',
        'towel',
        'water',
        'pillow',
        'iron',
        'laundry',
        'maintenance',
        'wifi',
        'other',
    ];

    protected $fillable = [
        'atelier_id',
        'name',
        'description',
        'icon_key',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(TableServiceRequest::class);
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'icon_key' => $this->icon_key ?: 'other',
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'price' => 0,
            'is_free' => true,
        ];
    }
}
