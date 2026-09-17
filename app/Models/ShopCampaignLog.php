<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopCampaignLog extends Model
{
    protected $table = 'shop_campaign_logs';

    protected $fillable = [
        'campaign_id',
        'run_id',
        'atelier_id',
        'phone',
        'status',
        'skip_reason',
        'actions_result',
    ];

    protected $casts = [
        'actions_result' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ShopCampaign::class, 'campaign_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ShopCampaignRun::class, 'run_id');
    }
}
