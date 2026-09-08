<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopDailyTicketCounter extends Model
{
    protected $fillable = [
        'atelier_id',
        'ticket_date',
        'last_number',
    ];

    protected $casts = [
        'atelier_id' => 'integer',
        'last_number' => 'integer',
        'ticket_date' => 'date',
    ];
}
