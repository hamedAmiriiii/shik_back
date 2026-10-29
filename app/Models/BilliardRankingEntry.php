<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BilliardRankingEntry extends Model
{
    protected $table = 'billiard_ranking_entries';

    protected $fillable = [
        'discipline_id',
        'player_id',
        'points',
        'rank',
        'tournaments_count',
        'wins',
        'losses',
        'titles',
        'runner_ups',
        'thirds',
        'computed_at',
        'source_tournament_id',
    ];

    protected $casts = [
        'points' => 'integer',
        'rank' => 'integer',
        'tournaments_count' => 'integer',
        'wins' => 'integer',
        'losses' => 'integer',
        'titles' => 'integer',
        'runner_ups' => 'integer',
        'thirds' => 'integer',
        'computed_at' => 'datetime',
    ];

    public function discipline(): BelongsTo
    {
        return $this->belongsTo(BilliardDiscipline::class, 'discipline_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(BilliardUser::class, 'player_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'discipline_id' => (int) $this->discipline_id,
            'player_id' => (int) $this->player_id,
            'points' => (int) $this->points,
            'rank' => (int) $this->rank,
            'tournaments_count' => (int) $this->tournaments_count,
            'wins' => (int) $this->wins,
            'losses' => (int) $this->losses,
            'titles' => (int) $this->titles,
            'runner_ups' => (int) $this->runner_ups,
            'thirds' => (int) $this->thirds,
            'win_rate' => ($this->wins + $this->losses) > 0
                ? round(100 * $this->wins / ($this->wins + $this->losses), 1)
                : null,
            'computed_at' => optional($this->computed_at)->toIso8601String(),
            'player' => $this->relationLoaded('player') && $this->player
                ? $this->player->toPublicArray($this)
                : null,
        ];
    }
}
