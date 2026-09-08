<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableServiceRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'atelier_id',
        'shop_table_id',
        'shop_service_id',
        'service_name',
        'icon_key',
        'phone',
        'note',
        'status',
        'scheduled_at',
        'done_at',
    ];

    protected $casts = [
        'done_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    public function shopTable(): BelongsTo
    {
        return $this->belongsTo(ShopTable::class);
    }

    public function shopService(): BelongsTo
    {
        return $this->belongsTo(ShopService::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public static function statusLabel(?string $status): string
    {
        $labels = [
            self::STATUS_PENDING => 'در انتظار زمان‌بندی',
            self::STATUS_SCHEDULED => 'زمان‌بندی شده',
            self::STATUS_DONE => 'انجام شد',
            self::STATUS_CANCELLED => 'لغو شده',
        ];

        return $labels[$status] ?? (string) $status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_SCHEDULED], true);
    }

    public function toPublicArray(): array
    {
        $this->loadMissing(['shopTable']);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => self::statusLabel($this->status),
            'service_id' => $this->shop_service_id,
            'name' => $this->service_name,
            'icon_key' => $this->icon_key ?: 'other',
            'note' => $this->note,
            'phone' => $this->phone,
            'is_free' => true,
            'price' => 0,
            'table_label' => $this->shopTable ? $this->shopTable->display_name : null,
            'table_number' => $this->shopTable ? (int) $this->shopTable->table_number : null,
            'created_at' => $this->created_at,
            'scheduled_at' => $this->scheduled_at,
            'done_at' => $this->done_at,
        ];
    }
}
