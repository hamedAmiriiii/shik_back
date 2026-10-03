<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RepairService extends Model
{
    protected $table = 'repair_services';

    protected $fillable = ['name', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(RepairUser::class, 'repair_technician_services', 'service_id', 'technician_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
