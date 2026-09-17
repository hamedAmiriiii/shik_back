<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopCampaignRun extends Model
{
    protected $table = 'shop_campaign_runs';

    protected $fillable = [
        'campaign_id',
        'atelier_id',
        'trigger',
        'matched_count',
        'sent_count',
        'skipped_count',
        'failed_count',
        'estimated_revenue',
        'status',
        'error_message',
    ];

    protected $casts = [
        'estimated_revenue' => 'decimal:2',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ShopCampaign::class, 'campaign_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ShopCampaignLog::class, 'run_id');
    }
}
