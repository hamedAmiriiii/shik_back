<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BilliardNationalEntryRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'در انتظار بررسی',
        self::STATUS_APPROVED => 'تأیید شده',
        self::STATUS_REJECTED => 'رد شده',
    ];

    protected $table = 'billiard_national_entry_requests';

    protected $fillable = [
        'player_id',
        'national_competition_id',
        'title',
        'discipline_id',
        'note',
        'status',
        'admin_note',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(BilliardUser::class, 'player_id');
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(BilliardNationalCompetition::class, 'national_competition_id');
    }

    public function discipline(): BelongsTo
    {
        return $this->belongsTo(BilliardDiscipline::class, 'discipline_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(BilliardUser::class, 'reviewed_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $player = $this->relationLoaded('player') ? $this->player : null;
        $discipline = $this->relationLoaded('discipline') ? $this->discipline : null;

        return [
            'id' => (int) $this->id,
            'player_id' => (int) $this->player_id,
            'national_competition_id' => $this->national_competition_id ? (int) $this->national_competition_id : null,
            'title' => $this->title,
            'discipline_id' => (int) $this->discipline_id,
            'discipline' => $discipline ? $discipline->toApiArray() : null,
            'note' => $this->note,
            'status' => $this->status,
            'status_label' => self::STATUS_LABELS[$this->status] ?? $this->status,
            'admin_note' => $this->admin_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'player' => $player ? [
                'id' => (int) $player->id,
                'full_name' => trim(($player->first_name ?? '').' '.($player->last_name ?? '')) ?: null,
                'phone' => $player->phone,
                'city' => $player->city,
                'club' => $player->club,
            ] : null,
        ];
    }
}
