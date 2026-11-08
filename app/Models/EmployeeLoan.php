<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLoan extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'atelier_id',
        'shop_employee_id',
        'title',
        'principal',
        'monthly_installment',
        'remaining',
        'status',
        'start_year',
        'start_month',
        'note',
    ];

    protected $casts = [
        'principal' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
        'remaining' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ShopEmployee::class, 'shop_employee_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && (float) $this->remaining > 0.01;
    }

    public function installmentForMonth(): float
    {
        if (! $this->isActive()) {
            return 0.0;
        }

        return round(min((float) $this->remaining, (float) $this->monthly_installment), 2);
    }

    public function applyInstallment(float $amount): void
    {
        $paid = round(min((float) $this->remaining, max(0, $amount)), 2);
        $remaining = round(max(0, (float) $this->remaining - $paid), 2);
        $this->update([
            'remaining' => $remaining,
            'status' => $remaining <= 0.01 ? self::STATUS_CLOSED : self::STATUS_ACTIVE,
        ]);
    }
}
