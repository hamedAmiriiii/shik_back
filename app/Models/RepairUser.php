<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class RepairUser extends Authenticatable
{
    use HasApiTokens;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TECHNICIAN = 'technician';

    public const ROLE_CUSTOMER = 'customer';

    protected $table = 'repair_users';

    protected $fillable = [
        'role',
        'name',
        'phone',
        'specialty',
        'labor_share_percent',
        'card_number',
        'address',
        'notes',
        'is_active',
        'last_login_at',
    ];

    protected $casts = [
        'labor_share_percent' => 'float',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isTechnician(): bool
    {
        return $this->role === self::ROLE_TECHNICIAN;
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function customerRequests(): HasMany
    {
        return $this->hasMany(RepairRequest::class, 'customer_id');
    }

    public function technicianRequests(): HasMany
    {
        return $this->hasMany(RepairRequest::class, 'technician_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(RepairPayout::class, 'technician_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSessionArray(): array
    {
        return [
            'id' => (int) $this->id,
            'role' => $this->role,
            'name' => $this->name,
            'phone' => $this->phone,
            'specialty' => $this->specialty,
            'address' => $this->address,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toTechnicianArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'specialty' => $this->specialty,
            'labor_share_percent' => (float) $this->labor_share_percent,
            'card_number' => $this->card_number,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
