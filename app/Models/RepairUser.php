<?php

namespace App\Models;

use App\Tools\ImageTools;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use RuntimeException;
use Throwable;

class RepairUser extends Authenticatable
{
    use HasApiTokens;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TECHNICIAN = 'technician';

    public const ROLE_CUSTOMER = 'customer';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_REJECTED = 'rejected';

    protected $table = 'repair_users';

    protected $fillable = [
        'role',
        'approval_status',
        'approval_note',
        'name',
        'phone',
        'specialty',
        'labor_share_percent',
        'rating_avg',
        'rating_count',
        'card_number',
        'sheba',
        'photo_path',
        'address',
        'notes',
        'is_active',
        'last_login_at',
    ];

    protected $casts = [
        'labor_share_percent' => 'float',
        'rating_avg' => 'float',
        'rating_count' => 'integer',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isTechnician(): bool
    {
        return $this->role === self::ROLE_TECHNICIAN;
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function isApproved(): bool
    {
        return ($this->approval_status ?: self::APPROVAL_APPROVED) === self::APPROVAL_APPROVED;
    }

    public function customerRequests(): HasMany
    {
        return $this->hasMany(RepairRequest::class, 'customer_id');
    }

    public function technicianRequests(): HasMany
    {
        return $this->hasMany(RepairRequest::class, 'technician_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(RepairPayout::class, 'technician_id');
    }

    public function refreshRating(): void
    {
        $stats = $this->technicianRequests()
            ->whereNotNull('rating')
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')
            ->first();
        $count = (int) ($stats->total ?? 0);
        $this->forceFill([
            'rating_avg' => $count > 0 ? round((float) $stats->avg_rating, 2) : null,
            'rating_count' => $count,
        ])->save();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(RepairService::class, 'repair_technician_services', 'technician_id', 'service_id');
    }

    /** ستون‌های شبا و عکس سلفی روی سرور ساخته شده‌اند یا نه */
    public static function hasIdentityColumns(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = Schema::hasColumn('repair_users', 'photo_path');
            } catch (Throwable $e) {
                $ready = false;
            }
        }

        return $ready;
    }

    /**
     * شماره کارت (۱۶ رقم) و شبا (IR + ۲۴ رقم) را یکدست و اعتبارسنجی می‌کند.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeBankFields(array $data): array
    {
        if (array_key_exists('card_number', $data)) {
            $card = self::digits($data['card_number']);
            if ($card !== '' && strlen($card) !== 16) {
                abort(response()->json(['message' => 'شماره کارت باید ۱۶ رقم باشد.'], 422));
            }
            $data['card_number'] = $card !== '' ? $card : null;
        }

        if (array_key_exists('sheba', $data)) {
            if (! self::hasIdentityColumns()) {
                unset($data['sheba']);
            } else {
                $sheba = self::digits($data['sheba']);
                if ($sheba !== '' && strlen($sheba) !== 24) {
                    abort(response()->json(['message' => 'شماره شبا باید IR و ۲۴ رقم باشد.'], 422));
                }
                $data['sheba'] = $sheba !== '' ? 'IR'.$sheba : null;
            }
        }

        return $data;
    }

    /**
     * ذخیرهٔ عکس سلفی از data URL (فقط تصویر، حداکثر ۴ مگابایت).
     */
    public function storeSelfie(string $dataUrl): void
    {
        $raw = $dataUrl;
        if (strpos($raw, ',') !== false) {
            $raw = substr($raw, strpos($raw, ',') + 1);
        }
        $content = base64_decode($raw, true);
        if ($content === false || $content === '') {
            throw new RuntimeException('عکس سلفی را ارسال کنید.');
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
        $path = ImageTools::saveFile("/repair-technicians/{$this->id}/selfie_".time().'.'.$ext, $content);
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
     * شبای قدیمی که در فیلد کارت ثبت شده بود جدا نمایش داده می‌شود.
     *
     * @return array{card_number: string|null, sheba: string|null}
     */
    public function bankInfo(): array
    {
        $card = $this->card_number ?: null;
        $sheba = $this->attributes['sheba'] ?? null;
        if (! $sheba && $card && strlen(self::digits($card)) === 24) {
            return ['card_number' => null, 'sheba' => 'IR'.self::digits($card)];
        }

        return ['card_number' => $card, 'sheba' => $sheba ?: null];
    }

    private static function digits($value): string
    {
        return (string) preg_replace('/\D/', '', strtr((string) $value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSessionArray(): array
    {
        return [
            'id' => (int) $this->id,
            'role' => $this->role,
            'name' => $this->name,
            'phone' => $this->phone,
            'specialty' => $this->specialty,
            'address' => $this->address,
        ] + ($this->role === self::ROLE_TECHNICIAN
            ? $this->bankInfo() + ['photo_url' => $this->photoUrl()]
            : ['card_number' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toTechnicianArray(): array
    {
        $services = $this->relationLoaded('services') ? $this->services : collect();

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'specialty' => $this->specialty,
            'labor_share_percent' => (float) $this->labor_share_percent,
            'rating_avg' => $this->rating_avg !== null ? round((float) $this->rating_avg, 2) : null,
            'rating_count' => (int) $this->rating_count,
            'card_number' => $this->bankInfo()['card_number'],
            'sheba' => $this->bankInfo()['sheba'],
            'photo_url' => $this->photoUrl(),
            'address' => $this->address,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
            'approval_status' => $this->approval_status ?: self::APPROVAL_APPROVED,
            'approval_note' => $this->approval_note,
            'service_ids' => $services->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'services' => $services->pluck('name')->values()->all(),
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
