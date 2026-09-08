<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DesktopLicense extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'license_key',
        'customer_name',
        'customer_phone',
        'customer_email',
        'max_devices',
        'starts_at',
        'expires_at',
        'status',
        'features',
        'notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'features' => 'array',
        'max_devices' => 'integer',
    ];

    public function activations(): HasMany
    {
        return $this->hasMany(DesktopLicenseActivation::class);
    }

    public function activeActivations(): HasMany
    {
        return $this->activations()->whereNull('revoked_at');
    }

    public function isUsable(): bool
    {
        if ($this->status === self::STATUS_REVOKED) {
            return false;
        }
        if ($this->expires_at && now()->greaterThan($this->expires_at)) {
            return false;
        }
        if ($this->starts_at && now()->lessThan($this->starts_at)) {
            return false;
        }

        return $this->status === self::STATUS_ACTIVE;
    }
}
