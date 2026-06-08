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
        'credit_used_total',
        'settlement_total',
        'discount_given',
        'deposit_account_1',
        'deposit_account_2',
        'deposit_cash',
        'deposited_total',
        'daily_discrepancy',
        'notes',
        'user_name',
        'deposit_record_account_1_id',
        'deposit_record_account_2_id',
        'deposit_record_cash_id',
    ];

    protected $casts = [
        'date' => 'date',
        'total_sales' => 'decimal:2',
        'card_amount' => 'decimal:2',
        'cash_amount' => 'decimal:2',
        'installments_collected' => 'decimal:2',
        'total_collected' => 'decimal:2',
        'credit_used_total' => 'decimal:2',
        'settlement_total' => 'decimal:2',
        'discount_given' => 'decimal:2',
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

    public function depositRecordAccount1(): BelongsTo
    {
        return $this->belongsTo(DailyShopReconciliationDeposit::class, 'deposit_record_account_1_id');
    }

    public function depositRecordAccount2(): BelongsTo
    {
        return $this->belongsTo(DailyShopReconciliationDeposit::class, 'deposit_record_account_2_id');
    }

    public function depositRecordCash(): BelongsTo
    {
        return $this->belongsTo(DailyShopReconciliationDeposit::class, 'deposit_record_cash_id');
    }
}
