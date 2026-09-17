<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopCampaignRule extends Model
{
    protected $table = 'shop_campaign_rules';

    protected $fillable = [
        'campaign_id',
        'conditions',
    ];

    protected $casts = [
        'conditions' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ShopCampaign::class, 'campaign_id');
    }
}
