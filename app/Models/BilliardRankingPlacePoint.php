<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BilliardRankingPlacePoint extends Model
{
    protected $table = 'billiard_ranking_place_points';

    protected $fillable = [
        'tournament_id',
        'place',
        'points',
    ];

    protected $casts = [
        'place' => 'integer',
        'points' => 'integer',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(BilliardTournament::class, 'tournament_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'place' => (int) $this->place,
            'points' => (int) $this->points,
        ];
    }
}
