<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BilliardNationalCompetition extends Model
{
    protected $table = 'billiard_national_competitions';

    protected $fillable = [
        'title',
        'discipline_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function discipline(): BelongsTo
    {
        return $this->belongsTo(BilliardDiscipline::class, 'discipline_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(BilliardNationalEntryRequest::class, 'national_competition_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'discipline_id' => (int) $this->discipline_id,
            'discipline' => $this->relationLoaded('discipline') && $this->discipline
                ? $this->discipline->toApiArray()
                : null,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
