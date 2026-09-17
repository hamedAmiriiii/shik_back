<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShopCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    protected $table = 'shop_campaigns';

    protected $fillable = [
        'atelier_id',
        'name',
        'status',
        'trigger',
        'cooldown_days',
        'max_recipients_per_run',
        'daily_sms_budget',
        'require_manual_approve',
        'description',
    ];

    protected $casts = [
        'require_manual_approve' => 'boolean',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(ShopCampaignRule::class, 'campaign_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ShopCampaignAction::class, 'campaign_id')->orderBy('sort');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ShopCampaignRun::class, 'campaign_id');
    }

    public function rule(): HasOne
    {
        return $this->hasOne(ShopCampaignRule::class, 'campaign_id');
    }
}
