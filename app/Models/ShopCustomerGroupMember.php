<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopCustomerGroupMember extends Model
{
    protected $table = 'shop_customer_group_members';

    protected $fillable = [
        'group_id',
        'phone',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ShopCustomerGroup::class, 'group_id');
    }
}
