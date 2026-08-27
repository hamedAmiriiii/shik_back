<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Morilog\Jalali\Jalalian;

/**
 * درخواست اعطای نمایندگی وبینو (ثبت عمومی، بدون لاگین).
 */
class AgencyRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'در انتظار بررسی',
        self::STATUS_CONTACTED => 'تماس گرفته شد',
        self::STATUS_APPROVED => 'تأیید شده',
        self::STATUS_REJECTED => 'رد شده',
    ];

    /** مدارک تحصیلی قابل انتخاب */
    public const EDUCATIONS = [
        'زیر دیپلم',
        'دیپلم',
        'فوق دیپلم',
        'لیسانس',
        'فوق لیسانس',
        'دکتری',
    ];

    protected $fillable = [
        'first_name',
        'last_name',
        'state_id',
        'city_id',
        'state_name',
        'city_name',
        'phone',
        'education',
        'status',
        'admin_note',
        'ip',
    ];

    protected $appends = ['full_name', 'status_label', 'created_at_jalali'];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getCreatedAtJalaliAttribute(): ?string
    {
        $raw = $this->getRawOriginal('created_at');
        if (! $raw) {
            return null;
        }

        return Jalalian::fromCarbon(
            Carbon::parse($raw)->setTimezone('Asia/Tehran')
        )->format('Y-m-d H:i:s');
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
