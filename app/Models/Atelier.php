<?php

namespace App\Models;

use App\Services\ChartOfAccountsSeeder;
use App\Support\ProjectType;
use App\Tools\QueryTools;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class Atelier extends Model
{
    use HasFactory, QueryTools;

    protected static function boot()
    {
        parent::boot();

        static::created(function (Atelier $atelier) {
            Setting::ensureDefaultsForAtelier((int) $atelier->id);

            $isOil = Schema::hasColumn('ateliers', 'project_type')
                && ProjectType::normalize($atelier->project_type ?? null) === ProjectType::OIL;

            if (! $isOil) {
                ShopAccount::ensureDefaultsForAtelier((int) $atelier->id);
                ChartOfAccountsSeeder::ensureForAtelier((int) $atelier->id);
            }

            if ($atelier->shop_access_starts_at === null && $atelier->shop_access_ends_at === null) {
                $atelier->forceFill(static::trialAccessAttributes())->saveQuietly();
            }
        });
    }

    /** مدت آزمایش رایگان پس از ثبت‌نام (روز) */
    public const TRIAL_DAYS = 7;

    /** @deprecated از TRIAL_DAYS استفاده شود */
    public const TRIAL_MONTHS = 1;

    public const SUBSCRIPTION_TRIAL = 'trial';

    public const SUBSCRIPTION_PAID = 'paid';

    protected $fillable = [
        'name',
        'code',
        'address',
        'business_license',
        'shop_access_starts_at',
        'shop_access_ends_at',
        'shop_access_suspended',
        'subscription_status',
        'referred_by_user_id',
        'paid_plan_activated_at',
        'project_type',
        'oil_interval_km',
    ];

    protected $casts = [
        'shop_access_starts_at' => 'datetime',
        'shop_access_ends_at' => 'datetime',
        'shop_access_suspended' => 'boolean',
        'paid_plan_activated_at' => 'datetime',
        'oil_interval_km' => 'integer',
    ];

    /**
     * آیا پرسنل متصل به این فروشگاه اجازهٔ ورود و کار با بخش فروشگاه را دارد؟
     */
    public function isShopAccessActive(): bool
    {
        if ($this->shop_access_suspended) {
            return false;
        }
        $now = now();
        if ($this->shop_access_starts_at && $now->lt($this->shop_access_starts_at)) {
            return false;
        }
        if ($this->shop_access_ends_at && $now->gt($this->shop_access_ends_at)) {
            return false;
        }

        return true;
    }

    /**
     * یک هفته استفاده رایگان از زمان ثبت‌نام.
     */
    public static function trialAccessAttributes(?Carbon $startsAt = null): array
    {
        $startsAt = $startsAt ?? now();

        return [
            'shop_access_starts_at' => $startsAt,
            'shop_access_ends_at' => $startsAt->copy()->addDays(self::TRIAL_DAYS),
            'shop_access_suspended' => false,
        ];
    }

    /**
     * وضعیت دوره دسترسی برای API (پنل فروشگاه / ادمین).
     */
    public function accessStatusForApi(): array
    {
        $ends = $this->shop_access_ends_at;
        $daysRemaining = null;
        if ($ends !== null) {
            $daysRemaining = $ends->isFuture()
                ? max(0, (int) now()->diffInDays($ends, false))
                : 0;
        }

        return [
            'shop_access_starts_at' => $this->shop_access_starts_at?->format('Y-m-d H:i:s'),
            'shop_access_ends_at' => $ends?->format('Y-m-d H:i:s'),
            'shop_access_suspended' => (bool) $this->shop_access_suspended,
            'shop_access_active' => $this->isShopAccessActive(),
            'shop_access_days_remaining' => $daysRemaining,
            'subscription_status' => $this->subscription_status ?? self::SUBSCRIPTION_TRIAL,
            'subscription_status_label' => self::subscriptionStatusLabel($this->subscription_status ?? self::SUBSCRIPTION_TRIAL),
            'is_paid_plan' => ($this->subscription_status ?? self::SUBSCRIPTION_TRIAL) === self::SUBSCRIPTION_PAID,
            'paid_plan_activated_at' => $this->paid_plan_activated_at?->format('Y-m-d H:i:s'),
        ];
    }

    public static function subscriptionStatusLabel(?string $status): string
    {
        return match ($status) {
            self::SUBSCRIPTION_PAID => 'پلن خریداری‌شده',
            self::SUBSCRIPTION_TRIAL => 'رایگان (آزمایشی)',
            default => (string) $status,
        };
    }

    public function isPaidPlan(): bool
    {
        return ($this->subscription_status ?? self::SUBSCRIPTION_TRIAL) === self::SUBSCRIPTION_PAID;
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function projectType(): string
    {
        return ProjectType::normalize($this->project_type ?? null);
    }

    public function isOilProject(): bool
    {
        return $this->projectType() === ProjectType::OIL;
    }

    public function oilIntervalKm(): int
    {
        $n = (int) ($this->oil_interval_km ?? 5000);

        return $n > 0 ? $n : 5000;
    }

    public function staffUsers()
    {
        return $this->hasMany(User::class, 'atelier_id');
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }


    public function getBusinessLicenseAttribute($value): string
    {
        return Storage::url($value);
    }
}
