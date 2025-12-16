<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = ['user_name', 'date', 'amount', 'title', 'type'];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getDateAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

}

