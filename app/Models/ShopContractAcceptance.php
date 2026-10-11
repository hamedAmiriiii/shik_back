<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopContractAcceptance extends Model
{
    protected $fillable = [
        'atelier_id',
        'user_id',
        'phone',
        'shop_name',
        'contract_version',
        'method',
        'ip',
        'user_agent',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];
}
