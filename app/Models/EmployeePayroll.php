<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayroll extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'atelier_id',
        'shop_employee_id',
        'payroll_year',
        'payroll_month',
        'hours_worked',
        'hourly_wage',
        'salary_amount',
        'base_salary_snapshot',
        'base_work_hours_snapshot',
        'overtime_hours',
        'overtime_amount',
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
        'base_salary_snapshot' => 'decimal:2',
        'base_work_hours_snapshot' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'overtime_amount' => 'decimal:2',
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

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeePayrollPayment::class, 'payroll_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    public function totalPaid(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function remaining(): float
    {
        return round(max(0, (float) $this->salary_amount - $this->totalPaid()), 2);
    }

    public function syncStatus(): void
    {
        $paid = $this->totalPaid();
        $salary = (float) $this->salary_amount;

        if ($paid <= 0) {
            $status = self::STATUS_PENDING;
        } elseif ($paid < $salary - 0.01) {
            $status = self::STATUS_PARTIAL;
        } else {
            $status = self::STATUS_PAID;
        }

        $this->update(['status' => $status]);
    }
}
