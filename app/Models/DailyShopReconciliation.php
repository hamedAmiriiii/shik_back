<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Morilog\Jalali\Jalalian;

class DailyShopReconciliation extends Model
{
    protected $fillable = [
        'atelier_id',
        'date',
        'total_sales',
        'card_amount',
        'cash_amount',
        'installments_collected',
        'total_collected',
        'deposit_account_1',
        'deposit_account_2',
        'deposit_cash',
        'deposited_total',
        'daily_discrepancy',
        'notes',
        'user_name',
        'invoice_account_1_id',
        'invoice_account_2_id',
        'invoice_cash_id',
    ];

    protected $casts = [
        'date' => 'date',
        'total_sales' => 'decimal:2',
        'card_amount' => 'decimal:2',
        'cash_amount' => 'decimal:2',
        'installments_collected' => 'decimal:2',
        'total_collected' => 'decimal:2',
        'deposit_account_1' => 'decimal:2',
        'deposit_account_2' => 'decimal:2',
        'deposit_cash' => 'decimal:2',
        'deposited_total' => 'decimal:2',
        'daily_discrepancy' => 'decimal:2',
    ];

    public function getDateAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function invoiceAccount1(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_account_1_id');
    }

    public function invoiceAccount2(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_account_2_id');
    }

    public function invoiceCash(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_cash_id');
    }
}
