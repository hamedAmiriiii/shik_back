<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = ['amount', 'title', 'description', 'date', 'user_name'];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getDateAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function getCreatedAtAttribute($value): string
    {
        if (!$value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');
        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }

    public function getUpdatedAtAttribute($value): string
    {
        if (!$value) {
            return null;
        }
        $carbon = \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran');
        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }
}

