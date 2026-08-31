<?php

namespace App\Models;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\ShopAccount;
use App\Services\DocumentPaymentService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class Cheque extends Model
{
    use HasFactory;

    public const TYPE_ISSUED = 'issued';
    public const TYPE_RECEIVED = 'received';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLEARED = 'cleared';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'atelier_id',
        'purchase_id',
        'invoice_id',
        'shop_account_id',
        'type',
        'cheque_number',
        'bank_name',
        'payee',
        'amount',
        'issue_date',
        'due_date',
        'title',
        'expense_type',
        'status',
        'expense_id',
        'income_id',
        'user_name',
        'note',
        'cleared_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issue_date' => 'date',
        'due_date' => 'date',
        'cleared_at' => 'datetime',
    ];

    protected $appends = [
        'type_label',
        'status_label',
        'issue_date_jalali',
        'due_date_jalali',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function income(): BelongsTo
    {
        return $this->belongsTo(Income::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_ISSUED => 'صادره',
            self::TYPE_RECEIVED => 'دریافتی',
            default => $this->type,
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'در انتظار وصول',
            self::STATUS_CLEARED => 'وصول‌شده',
            self::STATUS_CANCELLED => 'باطل‌شده',
            default => $this->status,
        };
    }

    public function getIssueDateJalaliAttribute(): ?string
    {
        $value = $this->attributes['issue_date'] ?? null;
        if (!$value) {
            return null;
        }

        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function getDueDateJalaliAttribute(): ?string
    {
        $value = $this->attributes['due_date'] ?? null;
        if (!$value) {
            return null;
        }

        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function getIssueDateAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }

        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function getDueDateAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }

        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function getCreatedAtAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }
        $carbon = Carbon::parse($value)->setTimezone('Asia/Tehran');

        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }

    public function getUpdatedAtAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }
        $carbon = Carbon::parse($value)->setTimezone('Asia/Tehran');

        return Jalalian::fromCarbon($carbon)->format('Y-m-d H:i:s');
    }

    public function ledgerTitle(): string
    {
        if ($this->title) {
            return $this->title;
        }

        $prefix = $this->type === self::TYPE_RECEIVED ? 'وصول چک دریافتی' : 'وصول چک صادره';
        $parts = [$prefix.' شماره '.$this->cheque_number];
        if ($this->payee) {
            $parts[] = $this->payee;
        }
        if ($this->bank_name) {
            $parts[] = $this->bank_name;
        }

        return implode(' - ', $parts);
    }

    /**
     * وصول چک: صادره → هزینه | دریافتی → درآمد
     */
    public function clear(?string $clearDate = null): self
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new RuntimeException('فقط چک‌های در انتظار وصول قابل وصول هستند.');
        }

        $date = $clearDate ?: Carbon::today('Asia/Tehran')->toDateString();

        return DB::transaction(function () use ($date) {
            $locked = static::where('id', $this->id)
                ->where('status', self::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new RuntimeException('چک قابل وصول نیست.');
            }

            if ($locked->type === self::TYPE_ISSUED) {
                $expense = null;
                $invoice = $locked->invoice_id ? Invoice::find($locked->invoice_id) : null;

                if (! $invoice && $locked->expense_id) {
                    $expense = Expense::find($locked->expense_id);
                } elseif (! $invoice) {
                    $expensePayload = [
                        'user_name' => $locked->user_name ?: 'سیستم',
                        'date' => $date,
                        'amount' => $locked->amount,
                        'title' => $locked->ledgerTitle(),
                        'type' => $locked->expense_type ?: 'جاری',
                        'atelier_id' => (int) $locked->atelier_id,
                        'shop_account_id' => $locked->shop_account_id,
                    ];
                    if (Schema::hasColumn('expenses', 'payment_status')) {
                        $expensePayload['payment_method'] = 'cheque';
                        $expensePayload['payment_status'] = 'unpaid';
                        $expensePayload['cheque_id'] = $locked->id;
                    }
                    $expense = Expense::create($expensePayload);
                    $locked->expense_id = $expense->id;
                    \App\Services\AccountingDocumentPoster::syncExpense($expense->fresh());
                }

                $accountId = $locked->shop_account_id
                    ?: ($invoice ? $invoice->shop_account_id : null)
                    ?: ($expense ? $expense->shop_account_id : null);

                $debited = false;
                if ($invoice) {
                    $debited = DocumentPaymentService::trySettleAfterClear($invoice, $accountId ? (int) $accountId : null, (int) $locked->id);
                } elseif ($expense) {
                    $debited = DocumentPaymentService::trySettleAfterClear($expense, $accountId ? (int) $accountId : null, (int) $locked->id);
                }

                $locked->update([
                    'status' => self::STATUS_CLEARED,
                    'expense_id' => $expense ? $expense->id : $locked->expense_id,
                    'cleared_at' => now(),
                ]);

                if ($debited) {
                    \App\Services\AccountingMiscPoster::postIssuedChequeClear($locked->fresh(['invoice', 'expense']));
                }
            } elseif ($locked->type === self::TYPE_RECEIVED) {
                if ($locked->income_id) {
                    throw new RuntimeException('برای این چک قبلاً درآمد ثبت شده است.');
                }

                $title = $locked->ledgerTitle();
                if ($locked->purchase_id) {
                    $title = 'وصول فروش چکی - '.$title;
                }

                $income = Income::create([
                    'user_name' => $locked->user_name ?: 'سیستم',
                    'date' => $date,
                    'amount' => $locked->amount,
                    'title' => $title,
                    'atelier_id' => (int) $locked->atelier_id,
                ]);

                $locked->update([
                    'status' => self::STATUS_CLEARED,
                    'income_id' => $income->id,
                    'cleared_at' => now(),
                ]);

                \App\Services\AccountingSalePoster::postChequeClear($locked->fresh());
            } else {
                throw new RuntimeException('نوع چک نامعتبر است.');
            }

            return $locked->fresh(['expense', 'income', 'purchase', 'invoice']);
        });
    }

    /**
     * برگشت وصول چک: حذف هزینه/درآمد مرتبط و بازگشت به وضعیت در انتظار
     */
    public function unclear(): self
    {
        if ($this->status !== self::STATUS_CLEARED) {
            throw new RuntimeException('فقط چک‌های وصول‌شده قابل برگشت وصول هستند.');
        }

        return DB::transaction(function () {
            $locked = static::where('id', $this->id)
                ->where('status', self::STATUS_CLEARED)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new RuntimeException('چک برای برگشت وصول در دسترس نیست.');
            }

            if ($locked->type === self::TYPE_ISSUED) {
                \App\Services\AccountingMiscPoster::reverseIssuedChequeClear($locked);
                if ($locked->invoice_id) {
                    $invoice = Invoice::find($locked->invoice_id);
                    if ($invoice) {
                        DocumentPaymentService::unpayCheque($invoice, (int) $locked->id);
                    }
                } elseif ($locked->expense_id) {
                    $expense = Expense::find($locked->expense_id);
                    if ($expense && DocumentPaymentService::supportsSplits() && $expense->payments()->where('cheque_id', $locked->id)->exists()) {
                        DocumentPaymentService::unpayCheque($expense, (int) $locked->id);
                    } elseif ($expense && (($expense->payment_method ?? null) === 'cheque' || $expense->cheque_id == $locked->id)) {
                        DocumentPaymentService::unpay($expense);
                    } else {
                        $expenseToDelete = Expense::find($locked->expense_id);
                        if ($expenseToDelete) {
                            \App\Services\AccountingDocumentPoster::reverseExpense($expenseToDelete);
                            $expenseToDelete->delete();
                        }
                        $locked->expense_id = null;
                    }
                }
            } elseif ($locked->type === self::TYPE_RECEIVED) {
                \App\Services\AccountingSalePoster::reverseChequeClear($locked);
                if ($locked->income_id) {
                    Income::where('id', $locked->income_id)->delete();
                }
            } else {
                throw new RuntimeException('نوع چک نامعتبر است.');
            }

            $locked->update([
                'status' => self::STATUS_PENDING,
                'income_id' => null,
                'cleared_at' => null,
                'expense_id' => $locked->expense_id,
            ]);

            return $locked->fresh(['expense', 'income', 'purchase', 'invoice']);
        });
    }
}
