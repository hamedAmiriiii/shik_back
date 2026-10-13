<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeneficiaryAdvanceApplication extends Model
{
    protected $fillable = [
        'atelier_id',
        'advance_id',
        'invoice_id',
        'expense_id',
        'document_payment_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryAdvance::class, 'advance_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function documentPayment(): BelongsTo
    {
        return $this->belongsTo(DocumentPayment::class);
    }
}
