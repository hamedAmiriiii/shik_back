<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopSmartAction extends Model
{
    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_EXECUTED = 'executed';

    public const TYPE_WINBACK = 'winback_credit_sms';
    public const TYPE_NEAR_VIP = 'near_vip_nudge';
    public const TYPE_READY_REPURCHASE = 'ready_repurchase';

    protected $table = 'shop_smart_actions';

    protected $fillable = [
        'atelier_id',
        'phone',
        'action_type',
        'priority',
        'title',
        'reason',
        'payload',
        'estimated_revenue',
        'status',
        'source',
        'campaign_id',
        'suggested_send_at',
        'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'estimated_revenue' => 'decimal:2',
        'suggested_send_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
