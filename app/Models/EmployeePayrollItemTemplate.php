<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayrollItemTemplate extends Model
{
    protected $fillable = [
        'atelier_id',
        'shop_employee_id',
        'item_type',
        'title',
        'amount',
        'is_active',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ShopEmployee::class, 'shop_employee_id');
    }
}
