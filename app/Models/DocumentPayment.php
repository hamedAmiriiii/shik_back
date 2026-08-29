<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPayment extends Model
{
    use HasFactory;

    public const METHOD_ACCOUNT = 'account';

    public const METHOD_CHEQUE = 'cheque';

    public const METHOD_CREDIT = 'credit';

    protected $fillable = [
        'atelier_id',
        'invoice_id',
        'expense_id',
        'method',
        'amount',
        'shop_account_id',
        'cheque_id',
        'settled',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'settled' => 'boolean',
    ];

    protected $appends = [
        'method_label',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function getMethodLabelAttribute(): string
    {
        return self::labelFor($this->attributes['method'] ?? '');
    }

    public static function labelFor(string $method): string
    {
        if ($method === self::METHOD_CHEQUE) {
            return 'چک';
        }
        if ($method === self::METHOD_CREDIT) {
            return 'نسیه';
        }
        if ($method === 'mixed') {
            return 'ترکیبی';
        }

        return 'نقد';
    }
}
