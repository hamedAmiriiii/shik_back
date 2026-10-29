<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BilliardTable extends Model
{
    protected $table = 'billiard_tables';

    protected $fillable = [
        'tournament_id',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(BilliardTournament::class, 'tournament_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BilliardMatch::class, 'table_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'tournament_id' => (int) $this->tournament_id,
            'name' => $this->name,
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
