<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BilliardMatch extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_LIVE = 'live';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_WALKOVER = 'walkover';

    protected $table = 'billiard_matches';

    protected $fillable = [
        'tournament_id',
        'round',
        'position',
        'round_label',
        'player1_id',
        'player2_id',
        'winner_id',
        'score1',
        'score2',
        'table_id',
        'scheduled_on',
        'scheduled_at',
        'status',
        'next_match_id',
        'next_slot',
        'referee_id',
        'notes',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'round' => 'integer',
        'position' => 'integer',
        'score1' => 'integer',
        'score2' => 'integer',
        'scheduled_on' => 'date',
        'next_slot' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(BilliardTournament::class, 'tournament_id');
    }

    public function player1(): BelongsTo
    {
        return $this->belongsTo(BilliardUser::class, 'player1_id');
    }

    public function player2(): BelongsTo
    {
        return $this->belongsTo(BilliardUser::class, 'player2_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(BilliardUser::class, 'winner_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(BilliardTable::class, 'table_id');
    }

    public function nextMatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_match_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(BilliardUser::class, 'referee_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $player = function (?BilliardUser $p) {
            return $p ? [
                'id' => (int) $p->id,
                'full_name' => $p->fullName(),
                'photo_url' => $p->photoUrl(),
            ] : null;
        };

        return [
            'id' => (int) $this->id,
            'tournament_id' => (int) $this->tournament_id,
            'round' => (int) $this->round,
            'position' => (int) $this->position,
            'round_label' => $this->round_label,
            'player1_id' => $this->player1_id ? (int) $this->player1_id : null,
            'player2_id' => $this->player2_id ? (int) $this->player2_id : null,
            'player1' => $this->relationLoaded('player1') ? $player($this->player1) : null,
            'player2' => $this->relationLoaded('player2') ? $player($this->player2) : null,
            'winner_id' => $this->winner_id ? (int) $this->winner_id : null,
            'score1' => $this->score1,
            'score2' => $this->score2,
            'table_id' => $this->table_id ? (int) $this->table_id : null,
            'table' => $this->relationLoaded('table') && $this->table ? $this->table->toApiArray() : null,
            'scheduled_on' => optional($this->scheduled_on)->format('Y-m-d'),
            'scheduled_at' => $this->scheduled_at ? substr((string) $this->scheduled_at, 0, 5) : null,
            'status' => $this->status,
            'next_match_id' => $this->next_match_id ? (int) $this->next_match_id : null,
            'next_slot' => $this->next_slot,
            'notes' => $this->notes,
            'finished_at' => optional($this->finished_at)->toIso8601String(),
        ];
    }
}
