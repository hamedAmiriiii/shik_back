<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\Expense;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class OutstandingSettlementService
{
    public const DIRECTION_RECEIVABLE = 'receivable';

    public const DIRECTION_PAYABLE = 'payable';

    public const KIND_CHEQUE = 'cheque';

    public const KIND_CREDIT = 'credit';

    public const KIND_INSTALLMENT = 'installment';

    /**
     * @return array{items: Collection<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function collect(int $atelierId, Request $request): array
    {
        $kinds = self::requestedKinds($request);
        $direction = self::requestedDirection($request);
        $today = Carbon::today('Asia/Tehran')->toDateString();

        $items = collect();

        if (self::wantsKind($kinds, self::KIND_CHEQUE)) {
            $items = $items->concat(self::pendingCheques($atelierId, $direction));
        }
        if (self::wantsKind($kinds, self::KIND_INSTALLMENT) && $direction !== self::DIRECTION_PAYABLE) {
            $items = $items->concat(self::unpaidInstallments($atelierId));
        }
        if (self::wantsKind($kinds, self::KIND_CREDIT)) {
            if ($direction !== self::DIRECTION_PAYABLE) {
                $items = $items->concat(self::openSaleDebts($atelierId));
            }
            if ($direction !== self::DIRECTION_RECEIVABLE) {
                $items = $items->concat(self::openDocumentCredits($atelierId, Invoice::class, 'invoice'));
                $items = $items->concat(self::openDocumentCredits($atelierId, Expense::class, 'expense'));
            }
        }

        $items = self::applyFilters($items, $request, $today)
            ->sortBy(function (array $row) {
                return ($row['due_date'] ?? '9999-12-31').'-'.str_pad((string) $row['id'], 10, '0', STR_PAD_LEFT);
            })
            ->values();

        return [
            'items' => $items,
            'summary' => self::summarize($items),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function summarize(Collection $items): array
    {
        $empty = [
            'count' => 0,
            'total' => 0.0,
            'cheque' => 0.0,
            'credit' => 0.0,
            'installment' => 0.0,
        ];
        $receivable = $empty;
        $payable = $empty;

        foreach ($items as $row) {
            $bucket = $row['direction'] === self::DIRECTION_PAYABLE ? 'payable' : 'receivable';
            $kind = $row['kind'];
            $amount = (float) $row['amount'];
            if ($bucket === 'payable') {
                $payable['count']++;
                $payable['total'] += $amount;
                if (isset($payable[$kind])) {
                    $payable[$kind] += $amount;
                }
            } else {
                $receivable['count']++;
                $receivable['total'] += $amount;
                if (isset($receivable[$kind])) {
                    $receivable[$kind] += $amount;
                }
            }
        }

        $round = static function (array $bucket): array {
            foreach (['total', 'cheque', 'credit', 'installment'] as $key) {
                $bucket[$key] = round((float) $bucket[$key], 2);
            }

            return $bucket;
        };

        return [
            'receivable' => $round($receivable),
            'payable' => $round($payable),
            'net' => round($receivable['total'] - $payable['total'], 2),
        ];
    }

    /**
     * @return list<string>|null
     */
    protected static function requestedKinds(Request $request): ?array
    {
        $raw = $request->input('kind', $request->input('kinds'));
        if ($raw === null || $raw === '' || $raw === 'all') {
            return null;
        }

        $parts = is_array($raw) ? $raw : preg_split('/[,\s]+/', (string) $raw);
        $allowed = [self::KIND_CHEQUE, self::KIND_CREDIT, self::KIND_INSTALLMENT];
        $kinds = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if (in_array($part, $allowed, true)) {
                $kinds[] = $part;
            }
        }

        return $kinds === [] ? null : array_values(array_unique($kinds));
    }

    protected static function requestedDirection(Request $request): ?string
    {
        $raw = strtolower(trim((string) $request->input('direction', $request->input('flow', ''))));
        if ($raw === '' || $raw === 'all') {
            $alias = strtolower(trim((string) $request->input('type', '')));
            $raw = $alias;
        }

        if (in_array($raw, ['receivable', 'income', 'in', 'دریافت', 'درآمد'], true)) {
            return self::DIRECTION_RECEIVABLE;
        }
        if (in_array($raw, ['payable', 'expense', 'out', 'پرداخت', 'هزینه'], true)) {
            return self::DIRECTION_PAYABLE;
        }

        return null;
    }

    /**
     * @param  list<string>|null  $kinds
     */
    protected static function wantsKind(?array $kinds, string $kind): bool
    {
        return $kinds === null || in_array($kind, $kinds, true);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected static function pendingCheques(int $atelierId, ?string $direction): Collection
    {
        $query = Cheque::query()
            ->where('atelier_id', $atelierId)
            ->where('status', Cheque::STATUS_PENDING);

        if ($direction === self::DIRECTION_RECEIVABLE) {
            $query->where('type', Cheque::TYPE_RECEIVED);
        } elseif ($direction === self::DIRECTION_PAYABLE) {
            $query->where('type', Cheque::TYPE_ISSUED);
        }

        return $query->orderBy('due_date')->orderBy('id')->get()->map(function (Cheque $cheque) {
            $due = self::rawDate($cheque, 'due_date');
            $isPayable = $cheque->type === Cheque::TYPE_ISSUED;

            return self::row([
                'id' => (int) $cheque->id,
                'kind' => self::KIND_CHEQUE,
                'direction' => $isPayable ? self::DIRECTION_PAYABLE : self::DIRECTION_RECEIVABLE,
                'amount' => (float) $cheque->amount,
                'due_date' => $due,
                'party' => $cheque->payee,
                'title' => $cheque->title ?: ($isPayable ? 'چک صادره' : 'چک دریافتی'),
                'reference' => $cheque->cheque_number,
                'source_type' => 'cheque',
                'source_id' => (int) $cheque->id,
                'invoice_id' => $cheque->invoice_id ? (int) $cheque->invoice_id : null,
                'expense_id' => $cheque->expense_id ? (int) $cheque->expense_id : null,
                'purchase_id' => $cheque->purchase_id ? (int) $cheque->purchase_id : null,
                'note' => $cheque->note,
            ]);
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected static function unpaidInstallments(int $atelierId): Collection
    {
        return Installment::query()
            ->where('is_paid', false)
            ->whereHas('purchase', function ($q) use ($atelierId) {
                $q->forAtelier($atelierId);
            })
            ->with(['purchase' => function ($q) {
                $q->select('id', 'phone', 'total_amount', 'payment_type', 'atelier_id');
            }])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(function (Installment $installment) {
                $purchase = $installment->purchase;

                return self::row([
                    'id' => (int) $installment->id,
                    'kind' => self::KIND_INSTALLMENT,
                    'direction' => self::DIRECTION_RECEIVABLE,
                    'amount' => (float) $installment->amount,
                    'due_date' => self::rawDate($installment, 'due_date'),
                    'party' => $purchase?->phone,
                    'title' => 'قسط '.($installment->installment_number ?? ''),
                    'reference' => $purchase ? 'فروش #'.$purchase->id : null,
                    'source_type' => 'installment',
                    'source_id' => (int) $installment->id,
                    'purchase_id' => $purchase ? (int) $purchase->id : null,
                    'installment_number' => $installment->installment_number,
                ]);
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected static function openSaleDebts(int $atelierId): Collection
    {
        return Purchase::query()
            ->forAtelier($atelierId)
            ->where('payment_type', 'debt')
            ->where('is_debt_settled', false)
            ->where('total_amount', '>', 0)
            ->orderBy('id')
            ->get()
            ->map(function (Purchase $purchase) {
                $amount = $purchase->outstandingDebtAmount();
                if ($amount <= 0) {
                    return null;
                }

                return self::row([
                    'id' => (int) $purchase->id,
                    'kind' => self::KIND_CREDIT,
                    'direction' => self::DIRECTION_RECEIVABLE,
                    'amount' => $amount,
                    'due_date' => self::rawDateTime($purchase, 'created_at'),
                    'party' => $purchase->phone,
                    'title' => 'نسیه فروش',
                    'reference' => 'فروش #'.$purchase->id,
                    'source_type' => 'purchase',
                    'source_id' => (int) $purchase->id,
                    'purchase_id' => (int) $purchase->id,
                ]);
            })
            ->filter()
            ->values();
    }

    /**
     * @param  class-string  $modelClass
     * @return Collection<int, array<string, mixed>>
     */
    protected static function openDocumentCredits(int $atelierId, string $modelClass, string $sourceType): Collection
    {
        $query = $modelClass::query()->where('atelier_id', $atelierId);

        if ($sourceType === 'expense') {
            CustomerCreditExpenseService::excludeAllCustomerCredit($query);
        }

        $hasStatus = Schema::hasColumn((new $modelClass)->getTable(), 'payment_status');
        if ($hasStatus) {
            $query->where(function ($q) {
                $q->whereIn('payment_status', [
                    DocumentPaymentService::STATUS_UNPAID,
                    DocumentPaymentService::STATUS_PARTIAL,
                ])->orWhere(function ($inner) {
                    $inner->where('payment_method', DocumentPaymentService::METHOD_CREDIT)
                        ->where('payment_status', DocumentPaymentService::STATUS_UNPAID);
                });
            });
        } else {
            $query->where(function ($q) {
                $q->where('payment_method', DocumentPaymentService::METHOD_CREDIT)
                    ->orWhereNull('shop_account_id');
            });
        }

        if (DocumentPaymentService::supportsSplits()) {
            $query->with('payments');
        }
        if (Schema::hasColumn((new $modelClass)->getTable(), 'beneficiary_id')) {
            $query->with(['beneficiary:id,phone,name']);
        }

        return $query->orderBy('id')->get()
            ->map(function ($model) use ($sourceType) {
                $amount = DocumentPaymentService::remainingCredit($model);
                if ($amount <= 0) {
                    return null;
                }

                $party = optional($model->beneficiary)->name
                    ?? optional($model->beneficiary)->phone
                    ?? ($model->user_name ?? null);

                return self::row([
                    'id' => (int) $model->id,
                    'kind' => self::KIND_CREDIT,
                    'direction' => self::DIRECTION_PAYABLE,
                    'amount' => $amount,
                    'due_date' => self::rawDate($model, 'date'),
                    'party' => $party,
                    'title' => $model->title ?: ($sourceType === 'invoice' ? 'فاکتور خرید نسیه' : 'هزینه نسیه'),
                    'reference' => ($sourceType === 'invoice' ? 'فاکتور #' : 'هزینه #').$model->id,
                    'source_type' => $sourceType,
                    'source_id' => (int) $model->id,
                    'invoice_id' => $sourceType === 'invoice' ? (int) $model->id : null,
                    'expense_id' => $sourceType === 'expense' ? (int) $model->id : null,
                    'payment_status' => $model->payment_status ?? null,
                ]);
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    protected static function applyFilters(Collection $items, Request $request, string $today): Collection
    {
        $from = self::parseFilterDate($request, 'start_date')
            ?? self::parseFilterDate($request, 'from_date');
        $to = self::parseFilterDate($request, 'end_date')
            ?? self::parseFilterDate($request, 'to_date');

        $due = strtolower(trim((string) $request->input('due', '')));
        if ($request->boolean('overdue')) {
            $due = 'overdue';
        }
        $days = max(1, min(90, (int) $request->input('days', 7)));
        $until = Carbon::parse($today, 'Asia/Tehran')->addDays($days)->toDateString();

        $search = self::searchNeedle($request);

        return $items->filter(function (array $row) use ($from, $to, $due, $today, $until, $search) {
            $date = $row['due_date'];
            if ($from && $date && $date < $from) {
                return false;
            }
            if ($to && $date && $date > $to) {
                return false;
            }
            if ($due === 'overdue' && (! $date || $date >= $today)) {
                return false;
            }
            if ($due === 'upcoming' && $date && $date < $today) {
                return false;
            }
            if (in_array($due, ['due_soon', 'soon'], true) && (! $date || $date < $today || $date > $until)) {
                return false;
            }
            if ($search === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', array_filter([
                $row['party'] ?? '',
                $row['title'] ?? '',
                $row['reference'] ?? '',
                $row['note'] ?? '',
            ])));

            return str_contains($haystack, $search);
        })->values();
    }

    protected static function searchNeedle(Request $request): string
    {
        $direct = trim((string) $request->input('search', $request->input('q', '')));
        if ($direct !== '') {
            return mb_strtolower($direct);
        }

        $phone = trim((string) $request->input('phone', ''));
        if ($phone !== '') {
            return mb_strtolower($phone);
        }

        $model = json_decode($request->input('searchFilterModel'));
        if (is_string($model) && $model !== '') {
            return mb_strtolower($model);
        }
        if (is_object($model)) {
            foreach (['phone', 'title', 'payee', 'cheque_number', 'q'] as $key) {
                if (! empty($model->{$key})) {
                    return mb_strtolower((string) $model->{$key});
                }
            }
        }

        return '';
    }

    protected static function parseFilterDate(Request $request, string $key): ?string
    {
        if (! $request->filled($key)) {
            return null;
        }

        $value = $request->input($key);
        if (is_array($value) || (is_string($value) && str_starts_with(ltrim($value), '{'))) {
            $parts = is_array($value) ? $value : json_decode($value, true);
            $parsed = DocumentPaymentService::parseJalaliDate($parts ?? []);

            return $parsed;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return Jalalian::fromFormat('Y-m-d', $text)->toCarbon()->format('Y-m-d');
        } catch (\Throwable $e) {
            try {
                return Carbon::parse($text)->format('Y-m-d');
            } catch (\Throwable $ignored) {
                return null;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function row(array $row): array
    {
        $due = $row['due_date'] ?? null;
        $today = Carbon::today('Asia/Tehran')->toDateString();
        $row['due_date_jalali'] = $due ? Jalalian::fromDateTime($due)->format('Y-m-d') : null;
        $row['overdue'] = $due !== null && $due < $today;
        $row['direction_label'] = $row['direction'] === self::DIRECTION_PAYABLE ? 'باید بدهیم' : 'باید بگیریم';
        $row['kind_label'] = match ($row['kind']) {
            self::KIND_CHEQUE => 'چک',
            self::KIND_INSTALLMENT => 'قسط',
            default => 'نسیه',
        };
        $row['amount'] = round((float) $row['amount'], 2);

        return $row;
    }

    protected static function rawDate($model, string $column): ?string
    {
        $value = $model->getRawOriginal($column) ?? $model->getAttributes()[$column] ?? null;
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function rawDateTime($model, string $column): ?string
    {
        return self::rawDate($model, $column);
    }
}
