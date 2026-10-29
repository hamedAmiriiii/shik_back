<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BilliardDiscipline extends Model
{
    protected $table = 'billiard_disciplines';

    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tournaments(): HasMany
    {
        return $this->hasMany(BilliardTournament::class, 'discipline_id');
    }

    public function rankingEntries(): HasMany
    {
        return $this->hasMany(BilliardRankingEntry::class, 'discipline_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
