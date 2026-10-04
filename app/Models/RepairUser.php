<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class RepairUser extends Authenticatable
{
    use HasApiTokens;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TECHNICIAN = 'technician';

    public const ROLE_CUSTOMER = 'customer';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_REJECTED = 'rejected';

    protected $table = 'repair_users';

    protected $fillable = [
        'role',
        'approval_status',
        'approval_note',
        'name',
        'phone',
        'specialty',
        'labor_share_percent',
        'rating_avg',
        'rating_count',
        'card_number',
        'address',
        'notes',
        'is_active',
        'last_login_at',
    ];

    protected $casts = [
        'labor_share_percent' => 'float',
        'rating_avg' => 'float',
        'rating_count' => 'integer',
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

    public function isApproved(): bool
    {
        return ($this->approval_status ?: self::APPROVAL_APPROVED) === self::APPROVAL_APPROVED;
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

    public function refreshRating(): void
    {
        $stats = $this->technicianRequests()
            ->whereNotNull('rating')
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')
            ->first();
        $count = (int) ($stats->total ?? 0);
        $this->forceFill([
            'rating_avg' => $count > 0 ? round((float) $stats->avg_rating, 2) : null,
            'rating_count' => $count,
        ])->save();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(RepairService::class, 'repair_technician_services', 'technician_id', 'service_id');
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
            'card_number' => $this->role === self::ROLE_TECHNICIAN ? $this->card_number : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toTechnicianArray(): array
    {
        $services = $this->relationLoaded('services') ? $this->services : collect();

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'specialty' => $this->specialty,
            'labor_share_percent' => (float) $this->labor_share_percent,
            'rating_avg' => $this->rating_avg !== null ? round((float) $this->rating_avg, 2) : null,
            'rating_count' => (int) $this->rating_count,
            'card_number' => $this->card_number,
            'address' => $this->address,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
            'approval_status' => $this->approval_status ?: self::APPROVAL_APPROVED,
            'approval_note' => $this->approval_note,
            'service_ids' => $services->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'services' => $services->pluck('name')->values()->all(),
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
