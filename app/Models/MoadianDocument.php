<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Morilog\Jalali\Jalalian;

class MoadianDocument extends Model
{
    public const SUBJECT_ORIGINAL = 1;

    public const SUBJECT_CORRECTION = 2;

    public const SUBJECT_CANCEL = 3;

    public const SUBJECT_RETURN = 4;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISCARDED = 'discarded';

    public const SUBJECT_LABELS = [
        self::SUBJECT_ORIGINAL => 'اصلی',
        self::SUBJECT_CORRECTION => 'اصلاحی',
        self::SUBJECT_CANCEL => 'ابطالی',
        self::SUBJECT_RETURN => 'برگشت از فروش',
    ];

    public const STATUS_LABELS = [
        self::STATUS_QUEUED => 'در صف ارسال',
        self::STATUS_SENT => 'ارسال‌شده، در انتظار نتیجه',
        self::STATUS_SUCCESS => 'ثبت‌شده در سامانه',
        self::STATUS_FAILED => 'ناموفق',
        self::STATUS_DISCARDED => 'کنار گذاشته‌شده',
    ];

    protected $fillable = [
        'atelier_id',
        'purchase_id',
        'subject',
        'invoice_type',
        'pattern',
        'reference_document_id',
        'reference_taxid',
        'memory_id',
        'serial',
        'inno',
        'taxid',
        'indatim',
        'indati2m',
        'insr',
        'payload',
        'source_fingerprint',
        'tprdis',
        'tdis',
        'tadis',
        'tvam',
        'todam',
        'tbill',
        'setm',
        'status',
        'uid',
        'reference_number',
        'errors',
        'warnings',
        'attempts',
        'next_attempt_at',
        'sent_at',
        'last_inquiry_at',
        'finalized_at',
        'triggered_by',
        'user_id',
    ];

    protected $casts = [
        'subject' => 'integer',
        'invoice_type' => 'integer',
        'pattern' => 'integer',
        'serial' => 'integer',
        'indatim' => 'integer',
        'indati2m' => 'integer',
        'insr' => 'boolean',
        'setm' => 'integer',
        'attempts' => 'integer',
        'errors' => 'array',
        'warnings' => 'array',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'last_inquiry_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (MoadianDocument $doc) {
            $original = $doc->getOriginal('status');
            if (in_array($original, [self::STATUS_SENT, self::STATUS_SUCCESS], true)) {
                foreach (['payload', 'taxid', 'serial', 'indatim', 'subject', 'reference_taxid'] as $locked) {
                    if ($doc->isDirty($locked)) {
                        throw new \LogicException('صورتحساب ارسال‌شده به سامانه مؤدیان قابل ویرایش نیست.');
                    }
                }
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(MoadianDocumentItem::class);
    }

    public function payloadArray(): array
    {
        $decoded = json_decode((string) $this->payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_FAILED], true);
    }

    /**
     * @return array<int, array{code:string, message:string}>
     */
    private static function messages($list): array
    {
        $out = [];
        foreach ((array) $list as $item) {
            if (is_string($item)) {
                $out[] = ['code' => '', 'message' => $item];
            } elseif (is_array($item)) {
                $out[] = ['code' => (string) ($item['code'] ?? ''), 'message' => (string) ($item['message'] ?? '')];
            }
        }

        return $out;
    }

    public function toApiArray(bool $withDetails = false): array
    {
        $issuedAt = $this->indatim
            ? Jalalian::fromCarbon(\Carbon\Carbon::createFromTimestampMs($this->indatim)->setTimezone('Asia/Tehran'))->format('Y-m-d H:i')
            : null;

        $row = [
            'id' => $this->id,
            'purchase_id' => $this->purchase_id,
            'subject' => $this->subject,
            'subject_label' => self::SUBJECT_LABELS[$this->subject] ?? (string) $this->subject,
            'invoice_type' => $this->invoice_type,
            'taxid' => $this->taxid,
            'reference_taxid' => $this->reference_taxid,
            'issued_at' => $issuedAt,
            'insr' => (bool) $this->insr,
            'tadis' => (float) $this->tadis,
            'tvam' => (float) $this->tvam,
            'todam' => (float) $this->todam,
            'tbill' => (float) $this->tbill,
            'status' => $this->status,
            'status_label' => self::STATUS_LABELS[$this->status] ?? $this->status,
            'reference_number' => $this->reference_number,
            'errors' => self::messages($this->errors),
            'warnings' => self::messages($this->warnings),
            'attempts' => $this->attempts,
            'sent_at' => $this->sent_at ? Jalalian::fromCarbon($this->sent_at)->format('Y-m-d H:i') : null,
            'triggered_by' => $this->triggered_by,
            'can_retry' => $this->status === self::STATUS_FAILED,
        ];

        if ($withDetails) {
            $row['items'] = $this->items->map(fn (MoadianDocumentItem $item) => $item->toApiArray())->values()->all();
            $row['payload'] = $this->payloadArray();
        }

        return $row;
    }
}
