<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopCampaignAction extends Model
{
    public const TYPE_GRANT_CREDIT = 'grant_credit';
    public const TYPE_SEND_SMS = 'send_sms';
    public const TYPE_CREATE_SMART_ACTION = 'create_smart_action';

    protected $table = 'shop_campaign_actions';

    protected $fillable = [
        'campaign_id',
        'sort',
        'type',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ShopCampaign::class, 'campaign_id');
    }
}
