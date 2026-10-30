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

    public const PHASE_GROUP = 'group';

    public const PHASE_KNOCKOUT = 'knockout';

    public const SOURCE_WINNER = 'winner';

    public const SOURCE_LOSER = 'loser';

    protected $fillable = [
        'tournament_id',
        'match_no',
        'phase',
        'group_code',
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
        'slot1_source_match_id',
        'slot1_source_outcome',
        'slot2_source_match_id',
        'slot2_source_outcome',
        'referee_id',
        'notes',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'match_no' => 'integer',
        'round' => 'integer',
        'position' => 'integer',
        'score1' => 'integer',
        'score2' => 'integer',
        'scheduled_on' => 'date',
        'next_slot' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** شماره بعدی بازی در تورنمنت — همیشه از ۱ شروع می‌شود (اگر بازی‌ای نباشد). */
    public static function nextMatchNo(int $tournamentId): int
    {
        $max = static::query()->where('tournament_id', $tournamentId)->max('match_no');

        return $max === null ? 1 : ((int) $max + 1);
    }

    /**
     * شماره‌های بازی را از ۱ تا N بدون فاصله دوباره می‌چیند (بعد از حذف).
     * ترتیب: match_no فعلی، سپس id.
     */
    public static function renumberTournament(int $tournamentId): void
    {
        $matches = static::query()
            ->where('tournament_id', $tournamentId)
            ->orderByRaw('CASE WHEN match_no IS NULL THEN 1 ELSE 0 END')
            ->orderBy('match_no')
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            return;
        }

        // برای رعایت UNIQUE موقت همه را خالی می‌کنیم
        static::query()->where('tournament_id', $tournamentId)->update(['match_no' => null]);

        $n = 1;
        foreach ($matches as $match) {
            $updates = ['match_no' => $n];
            if ($match->phase === self::PHASE_GROUP && $match->group_code) {
                $updates['round_label'] = 'گروه '.$match->group_code.' · بازی '.$n;
            }
            static::query()->where('id', $match->id)->update($updates);
            $n++;
        }
    }

    public function displayNo(): int
    {
        return (int) ($this->match_no ?: $this->id);
    }

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

    /**
     * نام رابطه نباید table باشد — با property مدل Eloquent ($table) تداخل دارد.
     */
    public function poolTable(): BelongsTo
    {
        return $this->belongsTo(BilliardTable::class, 'table_id');
    }

    public function nextMatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_match_id');
    }

    public function slot1SourceMatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'slot1_source_match_id');
    }

    public function slot2SourceMatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'slot2_source_match_id');
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

        $sourceLabel = function (?int $matchId, ?string $outcome): ?array {
            if (! $matchId || ! $outcome) {
                return null;
            }
            $src = self::query()->find($matchId);
            $no = $src ? $src->displayNo() : $matchId;

            return [
                'match_id' => (int) $matchId,
                'match_no' => (int) $no,
                'outcome' => $outcome,
                'label' => ($outcome === self::SOURCE_LOSER ? 'بازنده' : 'برنده').' بازی '.$no,
            ];
        };

        $poolTable = $this->relationLoaded('poolTable') ? $this->getRelation('poolTable') : null;

        return [
            'id' => (int) $this->id,
            'tournament_id' => (int) $this->tournament_id,
            'match_no' => $this->displayNo(),
            'phase' => $this->phase ?: self::PHASE_KNOCKOUT,
            'group_code' => $this->group_code ?: null,
            'round' => (int) $this->round,
            'position' => (int) $this->position,
            'round_label' => $this->round_label,
            'player1_id' => $this->player1_id ? (int) $this->player1_id : null,
            'player2_id' => $this->player2_id ? (int) $this->player2_id : null,
            'player1' => $this->relationLoaded('player1') ? $player($this->player1) : null,
            'player2' => $this->relationLoaded('player2') ? $player($this->player2) : null,
            'player1_source' => $sourceLabel(
                $this->slot1_source_match_id ? (int) $this->slot1_source_match_id : null,
                $this->slot1_source_outcome
            ),
            'player2_source' => $sourceLabel(
                $this->slot2_source_match_id ? (int) $this->slot2_source_match_id : null,
                $this->slot2_source_outcome
            ),
            'winner_id' => $this->winner_id ? (int) $this->winner_id : null,
            'score1' => $this->score1,
            'score2' => $this->score2,
            'table_id' => $this->table_id ? (int) $this->table_id : null,
            'table' => $poolTable ? $poolTable->toApiArray() : null,
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
