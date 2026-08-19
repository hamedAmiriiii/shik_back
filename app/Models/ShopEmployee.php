<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopEmployee extends Model
{
    protected $fillable = [
        'atelier_id',
        'name',
        'phone',
        'is_active',
        'base_salary',
        'base_work_hours',
        'hourly_wage',
        'note',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'base_salary' => 'decimal:2',
        'base_work_hours' => 'decimal:2',
        'hourly_wage' => 'decimal:2',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(EmployeePayroll::class);
    }

    /**
     * محاسبه حقوق بر اساس ساعت کارکرد.
     * اگر ساعت واقعی >= ساعت پایه: پایه + اضافه‌کاری
     * اگر کمتر: نسبی از پایه
     */
    public function calculateSalary(float $hoursWorked): array
    {
        $baseSalary = (float) $this->base_salary;
        $baseWorkHours = (float) $this->base_work_hours;
        $hourlyWage = (float) $this->hourly_wage;

        if ($baseSalary <= 0) {
            // اگر پایه تعریف نشده: فقط ساعتی
            $earnedBase = 0;
            $overtimeHours = $hoursWorked;
            $overtimeAmount = round($hourlyWage * $hoursWorked, 2);
            $total = $overtimeAmount;
        } elseif ($baseWorkHours <= 0 || $hoursWorked >= $baseWorkHours) {
            // کارکرد کامل یا بیشتر
            $earnedBase = $baseSalary;
            $overtimeHours = max(0, $hoursWorked - $baseWorkHours);
            $overtimeAmount = round($hourlyWage * $overtimeHours, 2);
            $total = round($earnedBase + $overtimeAmount, 2);
        } else {
            // کارکرد ناقص
            $earnedBase = round(($baseSalary / $baseWorkHours) * $hoursWorked, 2);
            $overtimeHours = 0;
            $overtimeAmount = 0;
            $total = $earnedBase;
        }

        return [
            'salary_amount' => $total,
            'base_salary_snapshot' => $baseSalary,
            'base_work_hours_snapshot' => $baseWorkHours,
            'overtime_hours' => $overtimeHours,
            'overtime_amount' => $overtimeAmount,
        ];
    }
}
