<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayrollItem extends Model
{
    public const TYPE_BONUS = 'bonus';
    public const TYPE_ALLOWANCE = 'allowance';
    public const TYPE_DEDUCTION = 'deduction';
    public const TYPE_TAX = 'tax';
    public const TYPE_INSURANCE = 'insurance';

    public const ADDITION_TYPES = [
        self::TYPE_BONUS,
        self::TYPE_ALLOWANCE,
    ];

    public const DEDUCTION_TYPES = [
        self::TYPE_DEDUCTION,
        self::TYPE_TAX,
        self::TYPE_INSURANCE,
    ];

    protected $fillable = [
        'atelier_id',
        'payroll_id',
        'item_type',
        'title',
        'amount',
        'is_auto',
        'sort_order',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_auto' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(EmployeePayroll::class, 'payroll_id');
    }

    public function isAddition(): bool
    {
        return in_array($this->item_type, self::ADDITION_TYPES, true);
    }

    public function signedAmount(): float
    {
        $amount = abs((float) $this->amount);
        return $this->isAddition() ? $amount : -$amount;
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_BONUS => 'پاداش',
            self::TYPE_ALLOWANCE => 'مزایا / بن',
            self::TYPE_DEDUCTION => 'کسر',
            self::TYPE_TAX => 'مالیات',
            self::TYPE_INSURANCE => 'بیمه',
            default => $type,
        };
    }

    public static function types(): array
    {
        return [
            self::TYPE_BONUS,
            self::TYPE_ALLOWANCE,
            self::TYPE_DEDUCTION,
            self::TYPE_TAX,
            self::TYPE_INSURANCE,
        ];
    }
}
