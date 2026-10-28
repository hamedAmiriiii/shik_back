<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChequeBookLeaf extends Model
{
    public const STATUS_UNUSED = 'unused';

    public const STATUS_USED = 'used';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'cheque_book_id',
        'leaf_number',
        'status',
        'cheque_id',
    ];

    protected $casts = [
        'leaf_number' => 'integer',
    ];

    protected $appends = [
        'status_label',
        'leaf_number_label',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(ChequeBook::class, 'cheque_book_id');
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function isUnused(): bool
    {
        return $this->status === self::STATUS_UNUSED;
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->status === self::STATUS_USED) {
            return 'استفاده‌شده';
        }
        if ($this->status === self::STATUS_VOID) {
            return 'باطل';
        }

        return 'آزاد';
    }

    public function getLeafNumberLabelAttribute(): string
    {
        return (string) $this->leaf_number;
    }
}
