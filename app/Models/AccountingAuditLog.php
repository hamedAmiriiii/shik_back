<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class AccountingAuditLog extends Model
{
    public const ACTION_CREATE = 'create';

    public const ACTION_CORRECT = 'correct';

    public const ACTION_REVERSE = 'reverse';

    public const ACTION_PRIOR_YEAR_ADJUST = 'prior_year_adjust';

    public const ACTION_LABELS = [
        self::ACTION_CREATE => 'سند جدید',
        self::ACTION_CORRECT => 'اصلاح سند',
        self::ACTION_REVERSE => 'برگشت سند',
        self::ACTION_PRIOR_YEAR_ADJUST => 'تعدیلات سنواتی',
    ];

    protected $fillable = [
        'atelier_id',
        'user_id',
        'action',
        'voucher_id',
        'related_voucher_id',
        'closed_through',
        'reason',
        'payload',
    ];

    protected $casts = [
        'atelier_id' => 'integer',
        'user_id' => 'integer',
        'voucher_id' => 'integer',
        'related_voucher_id' => 'integer',
        'closed_through' => 'date',
        'payload' => 'array',
    ];

    public static function tableReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = Schema::hasTable('accounting_audit_logs');
        }

        return $ready;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(AccountingVoucher::class, 'voucher_id');
    }

    public function relatedVoucher(): BelongsTo
    {
        return $this->belongsTo(AccountingVoucher::class, 'related_voucher_id');
    }

    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where('atelier_id', $atelierId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $closed = $this->closed_through;
        $created = $this->created_at;

        return [
            'id' => (int) $this->id,
            'action' => $this->action,
            'action_label' => self::ACTION_LABELS[$this->action] ?? $this->action,
            'user_id' => $this->user_id,
            'user_name' => $this->relationLoaded('user') && $this->user ? $this->user->name : null,
            'voucher_id' => $this->voucher_id,
            'voucher_number' => $this->relationLoaded('voucher') && $this->voucher ? (int) $this->voucher->number : null,
            'related_voucher_id' => $this->related_voucher_id,
            'related_voucher_number' => $this->relationLoaded('relatedVoucher') && $this->relatedVoucher
                ? (int) $this->relatedVoucher->number
                : null,
            'in_closed_period' => $closed !== null,
            'closed_through' => $closed ? Jalalian::fromCarbon(Carbon::parse($closed))->format('Y-m-d') : null,
            'reason' => $this->reason,
            'payload' => $this->payload,
            'created_at' => $created
                ? Jalalian::fromCarbon(Carbon::parse($created)->timezone('Asia/Tehran'))->format('Y-m-d H:i')
                : null,
        ];
    }
}
