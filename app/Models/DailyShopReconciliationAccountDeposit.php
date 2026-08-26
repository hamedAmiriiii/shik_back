<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyShopReconciliationAccountDeposit extends Model
{
    protected $table = 'daily_shop_reconciliation_account_deposits';

    protected $fillable = [
        'reconciliation_id',
        'shop_account_id',
        'amount',
        'deposit_record_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(DailyShopReconciliation::class, 'reconciliation_id');
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }

    public function depositRecord(): BelongsTo
    {
        return $this->belongsTo(DailyShopReconciliationDeposit::class, 'deposit_record_id');
    }
}
