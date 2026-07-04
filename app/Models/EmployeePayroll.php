<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayroll extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'atelier_id',
        'shop_employee_id',
        'payroll_year',
        'payroll_month',
        'hours_worked',
        'hourly_wage',
        'salary_amount',
        'status',
        'paid_at',
        'paid_by_user_id',
        'expense_id',
        'note',
    ];

    protected $casts = [
        'hours_worked' => 'decimal:2',
        'hourly_wage' => 'decimal:2',
        'salary_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ShopEmployee::class, 'shop_employee_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
