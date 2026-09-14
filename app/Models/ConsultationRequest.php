<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * درخواست مشاوره / خرید از لندینگ (مثلاً منوی دیجیتال).
 */
class ConsultationRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_DONE = 'done';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'در انتظار بررسی',
        self::STATUS_CONTACTED => 'تماس گرفته شد',
        self::STATUS_DONE => 'انجام شد',
        self::STATUS_REJECTED => 'رد شده',
    ];

    public const SOURCE_DIGITAL_MENU = 'digital_menu';

    public const SOURCE_ACCOUNTING = 'accounting';

    public const SOURCES = [
        self::SOURCE_DIGITAL_MENU => 'منوی دیجیتال',
        self::SOURCE_ACCOUNTING => 'حسابداری و فروش',
    ];

    protected $fillable = [
        'name',
        'phone',
        'state_id',
        'city_id',
        'state_name',
        'city_name',
        'business_name',
        'source',
        'status',
        'admin_note',
        'ip',
    ];

    protected $appends = ['status_label', 'source_label'];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCES[$this->source] ?? (string) ($this->source ?: '—');
    }
}
