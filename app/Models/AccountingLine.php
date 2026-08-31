<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingLine extends Model
{
    protected $fillable = [
        'voucher_id',
        'account_id',
        'debit',
        'credit',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'sort_order' => 'integer',
        'account_id' => 'integer',
        'voucher_id' => 'integer',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(AccountingVoucher::class, 'voucher_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'account_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $account = $this->relationLoaded('account') ? $this->account : $this->account()->first();

        return [
            'id' => $this->id,
            'account_id' => (int) $this->account_id,
            'account_code' => $account->code ?? null,
            'account_name' => $account->name ?? null,
            'debit' => round((float) $this->debit, 2),
            'credit' => round((float) $this->credit, 2),
            'description' => $this->description,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
