<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopCustomerGroup extends Model
{
    protected $table = 'shop_customer_groups';

    protected $fillable = [
        'atelier_id',
        'name',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(ShopCustomerGroupMember::class, 'group_id');
    }
}
