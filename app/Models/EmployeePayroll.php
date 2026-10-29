<?php

namespace App\Models;

use App\Services\AccountingPeriodCloseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Morilog\Jalali\Jalalian;

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
        'days_worked',
        'salary_type_snapshot',
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
        'days_worked' => 'decimal:2',
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

    /**
     * آیا پرداخت از نوع «حقوق» ثبت شده؟ (مساعده به‌تنهایی قفل محاسبه حقوق نیست)
     */
    public function hasSalaryPayments(): bool
    {
        return $this->payments()
            ->where('payment_type', EmployeePayrollPayment::TYPE_SALARY)
            ->exists();
    }

    public function totalPaid(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function totalAdvances(): float
    {
        return round((float) $this->payments()
            ->where('payment_type', EmployeePayrollPayment::TYPE_ADVANCE)
            ->sum('amount'), 2);
    }

    /**
     * مانده قابل پرداخت حقوق پس از کسر مساعده و سایر پرداخت‌ها
     */
    public function remaining(): float
    {
        if (! $this->isSalaryCalculated()) {
            return 0.0;
        }

        return round(max(0, (float) $this->salary_amount - $this->totalPaid()), 2);
    }

    /**
     * مبلغی که بیش از حقوق پرداخت شده (مثلاً مساعده بیشتر از حقوق نهایی)
     */
    public function overpaidAmount(): float
    {
        if (! $this->isSalaryCalculated()) {
            return $this->totalPaid();
        }

        return round(max(0, $this->totalPaid() - (float) $this->salary_amount), 2);
    }

    /**
     * آیا ساعت/مبلغ حقوق این ماه محاسبه شده؟ (مساعده به‌تنهایی محاسبه محسوب نمی‌شود)
     */
    public function isSalaryCalculated(): bool
    {
        return (float) $this->hours_worked > 0.001
            || (float) ($this->days_worked ?? 0) > 0.001
            || (float) $this->salary_amount > 0.01;
    }

    public function isDailySalary(): bool
    {
        $type = $this->salary_type_snapshot
            ?: ($this->employee?->salary_type ?? ShopEmployee::SALARY_TYPE_MONTHLY);

        return $type === ShopEmployee::SALARY_TYPE_DAILY;
    }

    /**
     * تا وقتی پرداخت «حقوق» ثبت نشده، می‌توان ساعت/حقوق را دوباره محاسبه کرد.
     */
    public function canRecalculateSalary(): bool
    {
        return ! $this->hasSalaryPayments();
    }

    public function syncStatus(): void
    {
        $paid = $this->totalPaid();
        $salary = (float) $this->salary_amount;

        if (! $this->isSalaryCalculated()) {
            // مساعده قبل از محاسبه حقوق، فیش را تسویه‌شده نشان ندهد
            $status = $paid > 0 ? self::STATUS_PARTIAL : self::STATUS_PENDING;
        } elseif ($paid <= 0) {
            $status = self::STATUS_PENDING;
        } elseif ($paid < $salary - 0.01) {
            $status = self::STATUS_PARTIAL;
        } else {
            $status = self::STATUS_PAID;
        }

        $this->update(['status' => $status]);
    }

    /**
     * خلاصه پرداخت برای پاسخ API
     *
     * @return array<string, float|int|bool|string>
     */
    public function paymentSummary(): array
    {
        return [
            'salary_amount' => round((float) $this->salary_amount, 2),
            'total_paid' => $this->totalPaid(),
            'total_advances' => $this->totalAdvances(),
            'remaining' => $this->remaining(),
            'overpaid_amount' => $this->overpaidAmount(),
            'status' => $this->status,
            'salary_calculated' => $this->isSalaryCalculated(),
            'can_recalculate' => $this->canRecalculateSalary(),
        ];
    }

    /**
     * تاریخ اسمی ماه حقوقی (آخرین روز همان ماه شمسی).
     */
    public function periodExpenseDate(): string
    {
        $year = (int) $this->payroll_year;
        $month = (int) $this->payroll_month;
        if ($year < 1300 || $month < 1 || $month > 12) {
            return now('Asia/Tehran')->format('Y-m-d');
        }

        return (new Jalalian($year, $month, 1))
            ->addMonths(1)
            ->subDays(1)
            ->toCarbon()
            ->timezone('Asia/Tehran')
            ->format('Y-m-d');
    }

    /**
     * تاریخ ثبت هزینه/سند پرداخت حقوق.
     * اگر ماه فیش داخل دورهٔ بسته‌شده باشد، روی امروز (دورهٔ باز) می‌نشیند تا پرداخت ممکن شود.
     */
    public function expenseDateForPosting(): string
    {
        $periodDate = $this->periodExpenseDate();
        $atelierId = (int) $this->atelier_id;
        if ($atelierId <= 0) {
            return $periodDate;
        }

        $closed = AccountingPeriodCloseService::closedThrough($atelierId);
        if ($closed && $periodDate <= $closed) {
            return now('Asia/Tehran')->format('Y-m-d');
        }

        return $periodDate;
    }

    public function expenseDateFellBackToOpenPeriod(): bool
    {
        return $this->expenseDateForPosting() !== $this->periodExpenseDate();
    }
}
