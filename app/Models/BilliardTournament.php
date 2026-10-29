<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BilliardTournament extends Model
{
    public const STATUS_PRE_REGISTRATION = 'pre_registration';

    public const STATUS_REGISTRATION_OPEN = 'registration_open';

    public const STATUS_REGISTRATION_CLOSED = 'registration_closed';

    public const STATUS_DRAWN = 'drawn';

    public const STATUS_LIVE = 'live';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_CANCELLED = 'cancelled';

    public const FORMAT_SINGLE_ELIMINATION = 'single_elimination';

    protected $table = 'billiard_tournaments';

    protected $fillable = [
        'name',
        'slug',
        'poster_path',
        'discipline_id',
        'format',
        'venue',
        'host_club',
        'starts_on',
        'ends_on',
        'starts_at_time',
        'capacity',
        'fee_toman',
        'registration_deadline',
        'description',
        'rules',
        'status',
        'drawn_at',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'capacity' => 'integer',
        'fee_toman' => 'integer',
        'registration_deadline' => 'datetime',
        'drawn_at' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_PRE_REGISTRATION,
            self::STATUS_REGISTRATION_OPEN,
            self::STATUS_REGISTRATION_CLOSED,
            self::STATUS_DRAWN,
            self::STATUS_LIVE,
            self::STATUS_FINISHED,
            self::STATUS_CANCELLED,
        ];
    }

    public function discipline(): BelongsTo
    {
        return $this->belongsTo(BilliardDiscipline::class, 'discipline_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(BilliardRegistration::class, 'tournament_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(BilliardTable::class, 'tournament_id')->orderBy('sort_order');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BilliardMatch::class, 'tournament_id');
    }

    public function placePoints(): HasMany
    {
        return $this->hasMany(BilliardRankingPlacePoint::class, 'tournament_id')->orderBy('place');
    }

    public function posterUrl(): ?string
    {
        return $this->poster_path ? url(Storage::url($this->poster_path)) : null;
    }

    public static function makeUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'tournament-'.Str::random(6);
        $slug = $base;
        $i = 2;
        while (static::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    public function acceptsRegistration(): bool
    {
        if (! in_array($this->status, [self::STATUS_PRE_REGISTRATION, self::STATUS_REGISTRATION_OPEN], true)) {
            return false;
        }
        if ($this->registration_deadline && $this->registration_deadline->isPast()) {
            return false;
        }
        if ($this->capacity) {
            $count = $this->registrations()
                ->whereIn('status', [BilliardRegistration::STATUS_PENDING, BilliardRegistration::STATUS_CONFIRMED])
                ->count();
            if ($count >= $this->capacity) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $detail = false): array
    {
        $data = [
            'id' => (int) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'poster_url' => $this->posterUrl(),
            'discipline_id' => (int) $this->discipline_id,
            'discipline' => $this->relationLoaded('discipline') && $this->discipline
                ? $this->discipline->toApiArray()
                : null,
            'format' => $this->format,
            'venue' => $this->venue,
            'host_club' => $this->host_club,
            'starts_on' => optional($this->starts_on)->format('Y-m-d'),
            'ends_on' => optional($this->ends_on)->format('Y-m-d'),
            'starts_at_time' => $this->starts_at_time ? substr((string) $this->starts_at_time, 0, 5) : null,
            'capacity' => $this->capacity !== null ? (int) $this->capacity : null,
            'fee_toman' => (int) $this->fee_toman,
            'registration_deadline' => optional($this->registration_deadline)->toIso8601String(),
            'status' => $this->status,
            'drawn_at' => optional($this->drawn_at)->toIso8601String(),
            'registrations_count' => $this->registrations_count
                ?? ($this->relationLoaded('registrations') ? $this->registrations->count() : null),
        ];

        if ($detail) {
            $data['description'] = $this->description;
            $data['rules'] = $this->rules;
            $data['place_points'] = $this->relationLoaded('placePoints')
                ? $this->placePoints->map(fn (BilliardRankingPlacePoint $p) => $p->toApiArray())->values()->all()
                : [];
        }

        return $data;
    }
}
