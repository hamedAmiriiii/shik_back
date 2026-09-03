<?php

namespace App\Models;

use App\Tools\PlateTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class OilVisit extends Model
{
    protected $fillable = [
        'atelier_id',
        'created_by',
        'client_id',
        'plate',
        'plate_display',
        'phone',
        'km',
        'next_km',
        'notes',
        'sms_sent',
        'sms_error',
    ];

    protected $casts = [
        'km' => 'integer',
        'next_km' => 'integer',
        'sms_sent' => 'boolean',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OilVisitItem::class, 'oil_visit_id')->orderBy('id');
    }

    public function purchase()
    {
        return $this->hasOne(Purchase::class, 'oil_visit_id');
    }

    public function scopeWithItems($query)
    {
        if (Schema::hasTable('oil_visit_items')) {
            $query->with('items');
        }
        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'oil_visit_id')) {
            $query->with('purchase');
        }

        return $query;
    }

    public function toApiArray(): array
    {
        $parsed = PlateTools::parse($this->plate);
        $created = $this->created_at;
        $items = $this->itemsPayload();
        $sale = 0.0;
        $cost = 0.0;
        foreach ($items as $item) {
            $sale += (float) ($item['sale_price'] ?? 0);
            $cost += (float) ($item['purchase_price'] ?? 0);
        }
        $sale = round($sale, 2);
        $cost = round($cost, 2);

        return [
            'id' => (int) $this->id,
            'client_id' => Schema::hasColumn('oil_visits', 'client_id')
                ? ($this->client_id ?: null)
                : null,
            'plate' => $this->plate,
            'plate_display' => $this->plate_display,
            'plate_parts' => $parsed ? [
                'serial' => $parsed['serial'],
                'letter' => $parsed['letter'],
                'middle' => $parsed['middle'],
                'province' => $parsed['province'],
            ] : null,
            'phone' => $this->phone,
            'km' => (int) $this->km,
            'next_km' => (int) $this->next_km,
            'notes' => $this->notes !== null && $this->notes !== '' ? (string) $this->notes : null,
            'items' => $items,
            'sale_amount' => $sale,
            'cost_amount' => $cost,
            'profit' => round($sale - $cost, 2),
            'purchase_id' => $this->linkedPurchaseId(),
            'sms_sent' => (bool) $this->sms_sent,
            'sms_error' => $this->sms_error,
            'created_at' => $created ? $created->format('Y-m-d H:i:s') : null,
            'created_at_jalali' => $created ? jdate($created)->format('Y/m/d H:i') : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function itemsPayload(): array
    {
        if (! Schema::hasTable('oil_visit_items')) {
            return [];
        }
        if (! $this->relationLoaded('items')) {
            $this->load('items');
        }

        return $this->items->map(fn (OilVisitItem $item) => $item->toApiArray())->values()->all();
    }

    protected function linkedPurchaseId(): ?int
    {
        if (! Schema::hasTable('purchases') || ! Schema::hasColumn('purchases', 'oil_visit_id')) {
            return null;
        }
        if ($this->relationLoaded('purchase')) {
            return $this->purchase ? (int) $this->purchase->id : null;
        }
        $id = Purchase::query()->where('oil_visit_id', $this->id)->value('id');

        return $id ? (int) $id : null;
    }
}
