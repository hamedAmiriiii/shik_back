<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopEmployee extends Model
{
    public const SALARY_TYPE_MONTHLY = 'monthly';
    public const SALARY_TYPE_DAILY = 'daily';

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
        'note',
        'permissions',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'base_salary' => 'decimal:2',
        'base_work_hours' => 'decimal:2',
        'hourly_wage' => 'decimal:2',
        'permissions' => 'array',
    ];

    protected $appends = [
        'has_login',
        'username',
        'permission_keys',
    ];

    public function isDailySalary(): bool
    {
        $type = $this->attributes['salary_type'] ?? self::SALARY_TYPE_MONTHLY;

        return $type === self::SALARY_TYPE_DAILY;
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
