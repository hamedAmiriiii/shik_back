<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BeneficiaryAdvance extends Model
{
    public const SOURCE_CHEQUE_TRANSFER = 'cheque_transfer';

    public const STATUS_OPEN = 'open';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_USED = 'used';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'atelier_id',
        'beneficiary_id',
        'amount',
        'remaining',
        'source',
        'cheque_id',
        'settled_invoice_id',
        'settled_expense_id',
        'status',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'remaining' => 'decimal:2',
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(UserShiksho::class, 'beneficiary_id');
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(BeneficiaryAdvanceApplication::class, 'advance_id');
    }

    public function refreshStatus(): void
    {
        $remaining = round((float) $this->remaining, 2);
        $amount = round((float) $this->amount, 2);
        if ($this->status === self::STATUS_REVERSED) {
            return;
        }
        if ($remaining <= 0.001) {
            $this->status = self::STATUS_USED;
        } elseif ($remaining + 0.001 < $amount) {
            $this->status = self::STATUS_PARTIAL;
        } else {
            $this->status = self::STATUS_OPEN;
        }
        $this->save();
    }
}
