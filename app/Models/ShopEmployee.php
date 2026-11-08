<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopEmployee extends Model
{
    public const SALARY_TYPE_MONTHLY = 'monthly';
    public const SALARY_TYPE_DAILY = 'daily';
    public const SALARY_TYPE_HOURLY = 'hourly';
    public const SALARY_TYPE_COMMISSION = 'commission';
    public const SALARY_TYPE_MIXED = 'mixed';
    public const SALARY_TYPE_FIXED = 'fixed';

    public const SALARY_TYPES = [
        self::SALARY_TYPE_MONTHLY,
        self::SALARY_TYPE_DAILY,
        self::SALARY_TYPE_HOURLY,
        self::SALARY_TYPE_COMMISSION,
        self::SALARY_TYPE_MIXED,
        self::SALARY_TYPE_FIXED,
    ];

    protected $fillable = [
        'atelier_id',
        'user_id',
        'name',
        'phone',
        'is_active',
        'salary_type',
        'base_salary',
        'base_work_hours',
        'hourly_wage',
        'commission_percent',
        'fixed_monthly_amount',
        'note',
        'permissions',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'base_salary' => 'decimal:2',
        'base_work_hours' => 'decimal:2',
        'hourly_wage' => 'decimal:2',
        'commission_percent' => 'decimal:2',
        'fixed_monthly_amount' => 'decimal:2',
        'permissions' => 'array',
    ];

    protected $appends = [
        'has_login',
        'username',
        'permission_keys',
    ];

    public function isDailySalary(): bool
    {
        return $this->salaryType() === self::SALARY_TYPE_DAILY;
    }

    public function salaryType(): string
    {
        $type = (string) ($this->attributes['salary_type'] ?? self::SALARY_TYPE_MONTHLY);

        return in_array($type, self::SALARY_TYPES, true) ? $type : self::SALARY_TYPE_MONTHLY;
    }

    public function itemTemplates(): HasMany
    {
        return $this->hasMany(EmployeePayrollItemTemplate::class, 'shop_employee_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class, 'shop_employee_id');
    }

    public function attendanceDays(): HasMany
    {
        return $this->hasMany(EmployeeAttendanceDay::class, 'shop_employee_id');
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getHasLoginAttribute(): bool
    {
        return ! empty($this->attributes['user_id']);
    }

    public function getUsernameAttribute(): ?string
    {
        $phone = $this->attributes['phone'] ?? null;

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /**
     * @return array<int, string>
     */
    public function getPermissionKeysAttribute(): array
    {
        $raw = $this->permissions;
        if (! is_array($raw)) {
            return [];
        }

        return \App\Services\ShopPermissionCatalog::sanitize($raw);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(EmployeePayroll::class);
    }

    /**
     * محاسبه حقوق.
     * ماهانه: بر اساس ساعت کارکرد (پایه + اضافه‌کار)
     * روزانه: روز کارکرد × دستمزد روزانه + ساعت اضافه‌کار × نرخ ساعتی
     *
     * @return array{
     *   salary_amount: float,
     *   base_salary_snapshot: float,
     *   base_work_hours_snapshot: float,
     *   overtime_hours: float,
     *   overtime_amount: float,
     *   days_worked: float,
     *   salary_type: string
     * }
     */
    public function calculateSalary(float $hoursWorked = 0, float $daysWorked = 0, float $overtimeHours = 0): array
    {
        // انواع جدید از طریق PayrollSalaryService محاسبه می‌شوند؛ این متد سازگاری قدیمی را نگه می‌دارد.
        if ($this->isDailySalary()) {
            return $this->calculateDailySalary($daysWorked, $overtimeHours);
        }

        return $this->calculateMonthlySalary($hoursWorked);
    }

    /**
     * @return array{
     *   salary_amount: float,
     *   base_salary_snapshot: float,
     *   base_work_hours_snapshot: float,
     *   overtime_hours: float,
     *   overtime_amount: float,
     *   days_worked: float,
     *   salary_type: string
     * }
     */
    public function calculateDailySalaryPublic(float $daysWorked, float $overtimeHours): array
    {
        return $this->calculateDailySalary($daysWorked, $overtimeHours);
    }

    /**
     * @return array{
     *   salary_amount: float,
     *   base_salary_snapshot: float,
     *   base_work_hours_snapshot: float,
     *   overtime_hours: float,
     *   overtime_amount: float,
     *   days_worked: float,
     *   salary_type: string
     * }
     */
    public function calculateMonthlySalaryPublic(float $hoursWorked): array
    {
        return $this->calculateMonthlySalary($hoursWorked);
    }

    /**
     * @return array{
     *   salary_amount: float,
     *   base_salary_snapshot: float,
     *   base_work_hours_snapshot: float,
     *   overtime_hours: float,
     *   overtime_amount: float,
     *   days_worked: float,
     *   salary_type: string
     * }
     */
    protected function calculateDailySalary(float $daysWorked, float $overtimeHours): array
    {
        $dailyWage = (float) $this->base_salary;
        $hourlyWage = (float) $this->hourly_wage;
        $days = max(0, $daysWorked);
        $otHours = max(0, $overtimeHours);
        $earnedBase = round($dailyWage * $days, 2);
        $overtimeAmount = round($hourlyWage * $otHours, 2);

        return [
            'salary_amount' => round($earnedBase + $overtimeAmount, 2),
            'base_salary_snapshot' => $dailyWage,
            'base_work_hours_snapshot' => 0,
            'overtime_hours' => $otHours,
            'overtime_amount' => $overtimeAmount,
            'days_worked' => $days,
            'salary_type' => self::SALARY_TYPE_DAILY,
        ];
    }

    /**
     * @return array{
     *   salary_amount: float,
     *   base_salary_snapshot: float,
     *   base_work_hours_snapshot: float,
     *   overtime_hours: float,
     *   overtime_amount: float,
     *   days_worked: float,
     *   salary_type: string
     * }
     */
    protected function calculateMonthlySalary(float $hoursWorked): array
    {
        $baseSalary = (float) $this->base_salary;
        $baseWorkHours = (float) $this->base_work_hours;
        $hourlyWage = (float) $this->hourly_wage;

        if ($baseSalary <= 0) {
            $overtimeHours = $hoursWorked;
            $overtimeAmount = round($hourlyWage * $hoursWorked, 2);
            $total = $overtimeAmount;
        } elseif ($baseWorkHours <= 0 || $hoursWorked >= $baseWorkHours) {
            $overtimeHours = max(0, $hoursWorked - $baseWorkHours);
            $overtimeAmount = round($hourlyWage * $overtimeHours, 2);
            $total = round($baseSalary + $overtimeAmount, 2);
        } else {
            $overtimeHours = 0;
            $overtimeAmount = 0;
            $total = round(($baseSalary / $baseWorkHours) * $hoursWorked, 2);
        }

        return [
            'salary_amount' => $total,
            'base_salary_snapshot' => $baseSalary,
            'base_work_hours_snapshot' => $baseWorkHours,
            'overtime_hours' => $overtimeHours,
            'overtime_amount' => $overtimeAmount,
            'days_worked' => 0,
            'salary_type' => self::SALARY_TYPE_MONTHLY,
        ];
    }
}
