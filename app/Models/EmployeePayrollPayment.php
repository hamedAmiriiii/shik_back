<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayrollPayment extends Model
{
    public const TYPE_SALARY = 'salary';
    public const TYPE_ADVANCE = 'advance';
    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'atelier_id',
        'payroll_id',
        'amount',
        'payment_type',
        'title',
        'paid_by_user_id',
        'expense_id',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(EmployeePayroll::class, 'payroll_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function typeLabel(): string
    {
        return match ($this->payment_type) {
            self::TYPE_SALARY => 'حقوق',
            self::TYPE_ADVANCE => 'مساعده',
            self::TYPE_OTHER => 'سایر',
            default => $this->payment_type,
        };
    }
}
