<?php

namespace App\Models;

use App\Tools\ImageTools;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use RuntimeException;

class BilliardUser extends Authenticatable
{
    use HasApiTokens;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_OPERATOR = 'operator';

    public const ROLE_PLAYER = 'player';

    protected $table = 'billiard_users';

    protected $fillable = [
        'role',
        'first_name',
        'last_name',
        'phone',
        'national_id',
        'birth_date',
        'photo_path',
        'city',
        'club',
        'membership_at',
        'membership_status',
        'is_active',
        'notes',
        'last_login_at',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'membership_at' => 'datetime',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isOperator(): bool
    {
        return $this->role === self::ROLE_OPERATOR;
    }

    public function isPlayer(): bool
    {
        return $this->role === self::ROLE_PLAYER;
    }

    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isOperator();
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: ($this->phone ?? '');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(BilliardRegistration::class, 'player_id');
    }

    public function storePhoto(string $dataUrl): void
    {
        $raw = $dataUrl;
        if (strpos($raw, ',') !== false) {
            $raw = substr($raw, strpos($raw, ',') + 1);
        }
        $content = base64_decode($raw, true);
        if ($content === false || $content === '') {
            throw new RuntimeException('عکس را ارسال کنید.');
        }
        if (strlen($content) > 4 * 1024 * 1024) {
            throw new RuntimeException('حجم عکس نباید بیشتر از ۴ مگابایت باشد.');
        }
        $info = @getimagesizefromstring($content);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if ($ext === null) {
            throw new RuntimeException('فایل ارسالی تصویر معتبر نیست.');
        }

        $oldPath = $this->photo_path;
        $path = ImageTools::saveFile("/billiard-players/{$this->id}/photo_".time().'.'.$ext, $content);
        $this->forceFill(['photo_path' => $path])->save();

        if ($oldPath && $oldPath !== $path && Storage::exists('public/'.$oldPath)) {
            Storage::delete('public/'.$oldPath);
        }
    }

    public function photoUrl(): ?string
    {
        $path = $this->attributes['photo_path'] ?? null;

        return $path ? url(Storage::url($path)) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(?BilliardRankingEntry $ranking = null): array
    {
        return [
            'id' => (int) $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'photo_url' => $this->photoUrl(),
            'city' => $this->city,
            'club' => $this->club,
            'membership_status' => $this->membership_status,
            'rank' => $ranking ? (int) $ranking->rank : null,
            'points' => $ranking ? (int) $ranking->points : null,
            'tournaments_count' => $ranking ? (int) $ranking->tournaments_count : null,
            'wins' => $ranking ? (int) $ranking->wins : null,
            'losses' => $ranking ? (int) $ranking->losses : null,
            'win_rate' => $ranking && (($ranking->wins + $ranking->losses) > 0)
                ? round(100 * $ranking->wins / ($ranking->wins + $ranking->losses), 1)
                : null,
            'titles' => $ranking ? (int) $ranking->titles : null,
            'runner_ups' => $ranking ? (int) $ranking->runner_ups : null,
            'thirds' => $ranking ? (int) $ranking->thirds : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSessionArray(): array
    {
        return [
            'id' => (int) $this->id,
            'role' => $this->role,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'phone' => $this->phone,
            'national_id' => $this->national_id,
            'birth_date' => optional($this->birth_date)->format('Y-m-d'),
            'photo_url' => $this->photoUrl(),
            'city' => $this->city,
            'club' => $this->club,
            'membership_status' => $this->membership_status,
            'profile_complete' => $this->isProfileComplete(),
            'results_tournaments' => $this->resultsClerkTournaments(),
        ];
    }

    /**
     * مسابقه‌هایی که این شماره فقط مجاز به ثبت نتیجه‌شان است.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function resultsClerkTournaments(): array
    {
        if (! Schema::hasColumn('billiard_tournaments', 'results_clerk_phone')) {
            return [];
        }

        return BilliardTournament::query()
            ->where('results_clerk_phone', $this->phone)
            ->orderByDesc('id')
            ->get(['id', 'name'])
            ->map(fn (BilliardTournament $t) => [
                'id' => (int) $t->id,
                'name' => $t->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return $this->toSessionArray() + [
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'membership_at' => optional($this->membership_at)->toIso8601String(),
            'last_login_at' => optional($this->last_login_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    public function isProfileComplete(): bool
    {
        return (bool) $this->first_name
            && (bool) $this->last_name
            && (bool) $this->photo_path
            && (bool) $this->phone;
    }
}
