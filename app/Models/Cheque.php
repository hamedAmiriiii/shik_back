<?php

namespace App\Models;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\ShopAccount;
use App\Services\DocumentPaymentService;
use App\Tools\JalaliTools;
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
    public const STATUS_TRANSFERRED = 'transferred';

    protected $fillable = [
        'atelier_id',
        'purchase_id',
        'invoice_id',
        'shop_account_id',
        'type',
        'cheque_number',
        'cheque_book_leaf_id',
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
        'transferred_at',
        'transferred_to_beneficiary_id',
        'transferred_to_invoice_id',
        'transferred_to_expense_id',
        'transfer_document_payment_id',
        'transfer_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issue_date' => 'date',
        'due_date' => 'date',
        'cleared_at' => 'datetime',
        'transferred_at' => 'datetime',
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

    public function chequeBookLeaf(): BelongsTo
    {
        return $this->belongsTo(ChequeBookLeaf::class, 'cheque_book_leaf_id');
    }

    public function transferredToBeneficiary(): BelongsTo
    {
        return $this->belongsTo(UserShiksho::class, 'transferred_to_beneficiary_id');
    }

    public function transferredToInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'transferred_to_invoice_id');
    }

    public function transferredToExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'transferred_to_expense_id');
    }

    public function transferDocumentPayment(): BelongsTo
    {
        return $this->belongsTo(DocumentPayment::class, 'transfer_document_payment_id');
    }

    public function getTypeLabelAttribute(): ?string
    {
        return match ($this->type) {
            self::TYPE_ISSUED => 'صادره',
            self::TYPE_RECEIVED => 'دریافتی',
            default => $this->type,
        };
    }

    public function getStatusLabelAttribute(): ?string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'در انتظار وصول',
            self::STATUS_CLEARED => 'وصول‌شده',
            self::STATUS_CANCELLED => 'باطل‌شده',
            self::STATUS_TRANSFERRED => 'منتقل‌شده (ظهرنویسی)',
            default => $this->status,
        };
    }

    public function getIssueDateJalaliAttribute(): ?string
    {
        return JalaliTools::format($this->attributes['issue_date'] ?? null, 'Y-m-d', null);
    }

    public function getDueDateJalaliAttribute(): ?string
    {
        return JalaliTools::format($this->attributes['due_date'] ?? null, 'Y-m-d', null);
    }

    public function getIssueDateAttribute($value): ?string
    {
        return JalaliTools::format($value, 'Y-m-d', null);
    }

    public function getDueDateAttribute($value): ?string
    {
        return JalaliTools::format($value, 'Y-m-d', null);
    }

    public function getCreatedAtAttribute($value): ?string
    {
        return JalaliTools::format($value);
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
     * ظهرنویسی چک دریافتی بابت بدهی فاکتور خرید یا هزینه نسیه — بدون حرکت صندوق.
     *
     * @param  array{invoice_id?: int|null, expense_id?: int|null, beneficiary_id?: int|null, note?: string|null}  $options
     */
    public function transfer(array $options = []): self
    {
        if ($this->type !== self::TYPE_RECEIVED) {
            throw new RuntimeException('فقط چک دریافتی قابل ظهرنویسی است.');
        }
        if ($this->status !== self::STATUS_PENDING) {
            throw new RuntimeException('فقط چک در انتظار وصول قابل ظهرنویسی است.');
        }

        $invoiceId = isset($options['invoice_id']) ? (int) $options['invoice_id'] : 0;
        $expenseId = isset($options['expense_id']) ? (int) $options['expense_id'] : 0;
        if (($invoiceId > 0) === ($expenseId > 0)) {
            throw new RuntimeException('یکی از فاکتور خرید یا هزینه را برای ظهرنویسی انتخاب کنید.');
        }

        return DB::transaction(function () use ($options, $invoiceId, $expenseId) {
            $locked = static::where('id', $this->id)
                ->where('status', self::STATUS_PENDING)
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                throw new RuntimeException('چک برای ظهرنویسی در دسترس نیست.');
            }

            if ($invoiceId > 0) {
                $document = Invoice::where('id', $invoiceId)
                    ->where('atelier_id', $locked->atelier_id)
                    ->lockForUpdate()
                    ->first();
                if (! $document) {
                    throw new RuntimeException('فاکتور خرید یافت نشد.');
                }
            } else {
                $document = Expense::where('id', $expenseId)
                    ->where('atelier_id', $locked->atelier_id)
                    ->lockForUpdate()
                    ->first();
                if (! $document) {
                    throw new RuntimeException('هزینه یافت نشد.');
                }
            }

            $settled = DocumentPaymentService::settleCreditWithReceivedCheque($document, $locked);
            $payment = $settled['payment'];
            $settledAmount = (float) $settled['settled_amount'];
            $advanceAmount = (float) $settled['advance_amount'];

            $beneficiaryId = isset($options['beneficiary_id']) ? (int) $options['beneficiary_id'] : 0;
            if ($beneficiaryId <= 0 && Schema::hasColumn($document->getTable(), 'beneficiary_id')) {
                $beneficiaryId = (int) ($document->beneficiary_id ?? 0);
            }

            if ($advanceAmount >= 0.01) {
                \App\Services\BeneficiaryAdvanceService::createFromChequeTransfer(
                    $locked,
                    $document,
                    $beneficiaryId,
                    $advanceAmount,
                    isset($options['note']) ? (string) $options['note'] : null
                );
            }

            $locked->update([
                'status' => self::STATUS_TRANSFERRED,
                'transferred_at' => now(),
                'transferred_to_invoice_id' => $document instanceof Invoice ? $document->id : null,
                'transferred_to_expense_id' => $document instanceof Expense ? $document->id : null,
                'transferred_to_beneficiary_id' => $beneficiaryId > 0 ? $beneficiaryId : null,
                'transfer_document_payment_id' => $payment->id,
                'transfer_note' => isset($options['note']) ? (string) $options['note'] : null,
            ]);

            $fresh = $locked->fresh([
                'transferredToInvoice',
                'transferredToExpense',
                'transferredToBeneficiary',
                'transferDocumentPayment',
            ]);
            $label = $document instanceof Invoice
                ? ('فاکتور خرید #'.$document->id)
                : ('هزینه #'.$document->id);
            \App\Services\AccountingMiscPoster::postChequeTransfer($fresh, $label, $settledAmount, $advanceAmount);

            return $fresh;
        });
    }

    /**
     * برگشت ظهرنویسی: بدهی سند دوباره باز می‌شود و چک به در انتظار وصول برمی‌گردد.
     */
    public function untransfer(): self
    {
        if ($this->status !== self::STATUS_TRANSFERRED) {
            throw new RuntimeException('فقط چک‌های منتقل‌شده (ظهرنویسی) قابل برگشت هستند.');
        }

        return DB::transaction(function () {
            $locked = static::where('id', $this->id)
                ->where('status', self::STATUS_TRANSFERRED)
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                throw new RuntimeException('چک برای برگشت ظهرنویسی در دسترس نیست.');
            }

            \App\Services\BeneficiaryAdvanceService::reverseForCheque($locked);
            \App\Services\AccountingMiscPoster::reverseChequeTransfer($locked);

            $document = null;
            if ($locked->transferred_to_invoice_id) {
                $document = Invoice::where('id', $locked->transferred_to_invoice_id)->lockForUpdate()->first();
            } elseif ($locked->transferred_to_expense_id) {
                $document = Expense::where('id', $locked->transferred_to_expense_id)->lockForUpdate()->first();
            }
            if (! $document) {
                throw new RuntimeException('سند بدهی مربوط به ظهرنویسی یافت نشد.');
            }

            $paymentId = (int) ($locked->transfer_document_payment_id ?? 0);
            $payment = $paymentId > 0
                ? DocumentPayment::where('id', $paymentId)->lockForUpdate()->first()
                : null;
            if (! $payment) {
                $payment = DocumentPayment::where('cheque_id', $locked->id)
                    ->where('settled', true)
                    ->where('method', DocumentPayment::METHOD_CHEQUE)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
            }
            if (! $payment) {
                throw new RuntimeException('پرداخت ظهرنویسی مربوط به این چک یافت نشد.');
            }

            DocumentPaymentService::untransferReceivedChequePayment($document, $payment);

            $locked->update([
                'status' => self::STATUS_PENDING,
                'transferred_at' => null,
                'transferred_to_beneficiary_id' => null,
                'transferred_to_invoice_id' => null,
                'transferred_to_expense_id' => null,
                'transfer_document_payment_id' => null,
                'transfer_note' => null,
            ]);

            return $locked->fresh(['expense', 'income', 'purchase', 'invoice']);
        });
    }

    /**
     * وصول چک: صادره → هزینه | دریافتی → درآمد
     *
     * @param  string|null  $clearDate  Y-m-d
     * @param  int|null  $shopAccountId  حساب بانکی / تنخواه / صندوق
     */
    public function clear(?string $clearDate = null, ?int $shopAccountId = null): self
    {
        if ($this->status === self::STATUS_TRANSFERRED) {
            throw new RuntimeException('چک ظهرنویسی‌شده قابل وصول بانکی نیست؛ ابتدا ظهرنویسی را برگشت دهید.');
        }
        if ($this->status !== self::STATUS_PENDING) {
            throw new RuntimeException('فقط چک‌های در انتظار وصول قابل وصول هستند.');
        }

        $date = $clearDate ?: Carbon::today('Asia/Tehran')->toDateString();

        return DB::transaction(function () use ($date, $shopAccountId) {
            $locked = static::where('id', $this->id)
                ->where('status', self::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new RuntimeException('چک قابل وصول نیست.');
            }

            if ($shopAccountId) {
                $locked->shop_account_id = $shopAccountId;
                $locked->save();
            }

            if ($locked->type === self::TYPE_ISSUED) {
                $expense = null;
                $invoice = $locked->invoice_id ? Invoice::find($locked->invoice_id) : null;

                if (! $invoice && $locked->expense_id) {
                    $expense = Expense::find($locked->expense_id);
                    if ($expense && $locked->shop_account_id && ! $expense->shop_account_id) {
                        $expense->shop_account_id = $locked->shop_account_id;
                        $expense->save();
                    }
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

                if ($invoice && $locked->shop_account_id && ! $invoice->shop_account_id) {
                    $invoice->shop_account_id = $locked->shop_account_id;
                    $invoice->save();
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

                if (! $debited) {
                    throw new RuntimeException('موجودی حساب برای وصول این چک کافی نیست. چک وصول نشد و مبلغی کسر نشد.');
                }

                $locked->update([
                    'status' => self::STATUS_CLEARED,
                    'expense_id' => $expense ? $expense->id : $locked->expense_id,
                    'shop_account_id' => $accountId ?: $locked->shop_account_id,
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

            return $locked->fresh(['expense', 'income', 'purchase', 'invoice', 'shopAccount']);
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
