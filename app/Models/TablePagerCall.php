<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TablePagerCall extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'atelier_id',
        'shop_table_id',
        'status',
        'note',
        'acknowledged_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function shopTable(): BelongsTo
    {
        return $this->belongsTo(ShopTable::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public static function statusLabel(?string $status): string
    {
        $labels = [
            self::STATUS_PENDING => 'در انتظار',
            self::STATUS_ACKNOWLEDGED => 'رسیدگی شد',
            self::STATUS_CANCELLED => 'لغو شده',
        ];

        return $labels[$status] ?? (string) $status;
    }

    public function toPublicArray(): array
    {
        $this->loadMissing(['shopTable']);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => self::statusLabel($this->status),
            'note' => $this->note,
            'table_label' => $this->shopTable ? $this->shopTable->display_name : null,
            'table_number' => $this->shopTable ? (int) $this->shopTable->table_number : null,
            'kind' => $this->shopTable ? $this->shopTable->kind : ShopTable::KIND_TABLE,
            'created_at' => $this->created_at,
            'acknowledged_at' => $this->acknowledged_at,
        ];
    }
}
