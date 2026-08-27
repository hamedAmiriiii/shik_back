<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class ManualTrade extends Model
{
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_SALE = 'sale';

    protected $fillable = [
        'atelier_id',
        'shop_account_id',
        'type',
        'title',
        'description',
        'amount',
        'date',
        'user_name',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected $appends = [
        'type_label',
    ];

    public static function types(): array
    {
        return [self::TYPE_PURCHASE, self::TYPE_SALE];
    }

    public static function tableReady(): bool
    {
        return Schema::hasTable('manual_trades');
    }

    public static function sumAmount(int $atelierId, ?string $type = null, ?string $fromDate = null, ?string $toDate = null): float
    {
        if (! self::tableReady()) {
            return 0.0;
        }

        $query = self::query()->where('atelier_id', $atelierId);
        if ($type) {
            $query->where('type', $type);
        }
        if ($fromDate) {
            $query->whereDate('date', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('date', '<=', $toDate);
        }

        return (float) $query->sum('amount');
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->type === self::TYPE_SALE ? 'فروش' : 'خرید';
    }

    public function getDateAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function getCreatedAtAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');

        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }

    public function getUpdatedAtAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');

        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }
}
