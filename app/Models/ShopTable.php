<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopTable extends Model
{
    public const KIND_TABLE = 'table';
    public const KIND_ROOM = 'room';

    protected $fillable = [
        'atelier_id',
        'table_number',
        'kind',
        'label',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'table_number' => 'integer',
    ];

    protected $appends = ['display_name'];

    public static function normalizeKind($kind): string
    {
        $value = strtolower(trim((string) $kind));

        return in_array($value, [self::KIND_ROOM, 'اتاق'], true)
            ? self::KIND_ROOM
            : self::KIND_TABLE;
    }

    public static function resolveFor(int $atelierId, int $number, $kind = self::KIND_TABLE): self
    {
        $normalized = self::normalizeKind($kind);

        return self::firstOrCreate(
            [
                'atelier_id' => $atelierId,
                'table_number' => $number,
                'kind' => $normalized,
            ],
            ['is_active' => true]
        );
    }

    public function isRoom(): bool
    {
        return $this->kind === self::KIND_ROOM;
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->label) {
            return $this->label;
        }

        return $this->isRoom()
            ? 'اتاق ' . $this->table_number
            : 'میز ' . $this->table_number;
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function pendingOrders(): HasMany
    {
        return $this->hasMany(TableOrder::class, 'shop_table_id')
            ->where('status', TableOrder::STATUS_PENDING);
    }
}
