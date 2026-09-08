<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesktopLicenseActivation extends Model
{
    protected $fillable = [
        'desktop_license_id',
        'machine_id',
        'machine_label',
        'app_version',
        'platform',
        'activated_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(DesktopLicense::class, 'desktop_license_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
