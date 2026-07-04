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
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(EmployeePayroll::class);
    }
}
