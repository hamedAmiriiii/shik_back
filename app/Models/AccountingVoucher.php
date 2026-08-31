<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class AccountingVoucher extends Model
{
    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_INSTALLMENT_PAY = 'installment_pay';

    public const SOURCE_DEBT_SETTLE = 'debt_settle';

    public const SOURCE_CHEQUE_CLEAR = 'cheque_clear';

    public const SOURCE_RECON_DEPOSIT = 'recon_deposit';

    public const SOURCE_ACCOUNT_TRANSFER = 'account_transfer';

    public const SOURCE_EXPENSE = 'expense';

    public const SOURCE_INVOICE = 'invoice';

    public const SOURCE_DOCUMENT_PAYMENT = 'document_payment';

    public const SOURCE_PRODUCTION = 'production';

    public const SOURCE_PURCHASE_RETURN = 'purchase_return';

    public const SOURCE_PAYROLL_PAYMENT = 'payroll_payment';

    public const SOURCE_MANUAL_TRADE = 'manual_trade';

    public const SOURCE_INCOME = 'income';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_OPENING = 'opening';

    protected $fillable = [
        'atelier_id',
        'number',
        'date',
        'description',
        'source_type',
        'source_id',
        'status',
        'reverses_voucher_id',
        'created_by',
        'active_source_key',
    ];

    protected $casts = [
        'date' => 'date',
        'number' => 'integer',
        'source_id' => 'integer',
        'atelier_id' => 'integer',
        'reverses_voucher_id' => 'integer',
        'created_by' => 'integer',
    ];

    protected $appends = [
        'date_jalali',
        'status_label',
    ];

    public static function tablesReady(): bool
    {
        return Schema::hasTable('accounting_vouchers') && Schema::hasTable('accounting_lines');
    }

    public static function activeSourceKey(string $sourceType, int $sourceId): string
    {
        return $sourceType.':'.$sourceId;
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AccountingLine::class, 'voucher_id')->orderBy('sort_order')->orderBy('id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_voucher_id');
    }

    public function reversedBy(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_voucher_id');
    }

    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where('atelier_id', $atelierId);
    }

    public function scopePosted($query)
    {
        return $query->where('status', self::STATUS_POSTED)->whereNull('reverses_voucher_id');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED && $this->reverses_voucher_id === null;
    }

    public function isReversal(): bool
    {
        return $this->reverses_voucher_id !== null;
    }

    public function getDateJalaliAttribute(): string
    {
        $date = $this->getAttribute('date');
        if (! $date) {
            return '';
        }

        return Jalalian::fromCarbon(\Carbon\Carbon::parse($date))->format('Y-m-d');
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->reverses_voucher_id) {
            return 'برگشت';
        }
        if ($this->status === self::STATUS_REVERSED) {
            return 'برگشت‌خورده';
        }

        return 'ثبت‌شده';
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $withLines = true): array
    {
        $debit = 0.0;
        $credit = 0.0;
        $lineRows = [];
        if ($withLines) {
            $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->with('account')->get();
            foreach ($lines as $line) {
                $debit += (float) $line->debit;
                $credit += (float) $line->credit;
                $lineRows[] = $line->toApiArray();
            }
        }

        return [
            'id' => $this->id,
            'number' => (int) $this->number,
            'date' => $this->date_jalali,
            'description' => $this->description,
            'source_type' => $this->source_type,
            'source_id' => (int) $this->source_id,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'reverses_voucher_id' => $this->reverses_voucher_id,
            'debit_total' => round($debit, 2),
            'credit_total' => round($credit, 2),
            'lines' => $withLines ? $lineRows : null,
        ];
    }
}
