<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\DocumentPayment;
use App\Models\Expense;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\PurchaseDebtPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

/**
 * رویدادهای مالی بازه (روز / هفته / ماه): دریافتی و پرداختی با تفکیک نقد، کارت، ترکیبی، نسیه، چک.
 */
class FinancialDayActivityService
{
    /**
     * @return array{
     *   meta: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   events: array<int, array<string, mixed>>
     * }
     */
    public static function report(
        int $atelierId,
        string $filter = 'today',
        ?string $fromJalali = null,
        ?string $toJalali = null
    ): array {
        [$start, $end, $filter] = self::rangeForFilter($filter, $fromJalali, $toJalali);
        $startString = $start->format('Y-m-d H:i:s');
        $endString = $end->format('Y-m-d H:i:s');
        $fromDate = $start->toDateString();
        $toDate = $end->toDateString();

        $events = [];

        self::appendSaleEvents($events, $atelierId, $start, $end, $startString, $endString);
        self::appendDebtSettlementEvents($events, $atelierId, $startString, $endString);
        self::appendInstallmentEvents($events, $atelierId, $startString, $endString);
        self::appendChequeEvents($events, $atelierId, $startString, $endString);
        self::appendDocumentOutflowEvents($events, $atelierId, $fromDate, $toDate);

        usort($events, function (array $a, array $b) {
            $ta = (string) ($a['at'] ?? '');
            $tb = (string) ($b['at'] ?? '');
            if ($ta === $tb) {
                return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
            }

            return strcmp($tb, $ta);
        });

        return [
            'meta' => [
                'filter' => $filter,
                'from' => $fromDate,
                'to' => $toDate,
                'from_jalali' => Jalalian::fromCarbon($start)->format('Y/m/d'),
                'to_jalali' => Jalalian::fromCarbon($end)->format('Y/m/d'),
                'events_count' => count($events),
            ],
            'summary' => self::buildSummary($events),
            'events' => $events,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    public static function rangeForFilter(
        string $filter,
        ?string $fromJalali = null,
        ?string $toJalali = null
    ): array {
        if ($filter === 'custom' || $fromJalali || $toJalali) {
            $start = self::parseJalaliDayStart($fromJalali) ?? self::parseJalaliDayStart($toJalali);
            $end = self::parseJalaliDayEnd($toJalali) ?? self::parseJalaliDayEnd($fromJalali);
            if ($start && $end) {
                if ($start->gt($end)) {
                    [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
                }

                return [$start, $end, 'custom'];
            }
        }

        $filter = in_array($filter, ['today', 'week', 'month'], true) ? $filter : 'today';
        $now = Jalalian::now();

        if ($filter === 'week') {
            $dayOfWeek = $now->getDayOfWeek(); // 0=شنبه ... 6=جمعه
            $start = Jalalian::now()->subDays($dayOfWeek)->toCarbon()->startOfDay()->setTimezone('Asia/Tehran');
            $end = Jalalian::now()->addDays(6 - $dayOfWeek)->toCarbon()->endOfDay()->setTimezone('Asia/Tehran');

            return [$start, $end, $filter];
        }

        if ($filter === 'month') {
            $start = (new Jalalian($now->getYear(), $now->getMonth(), 1))
                ->toCarbon()
                ->startOfDay()
                ->setTimezone('Asia/Tehran');
            $end = (new Jalalian($now->getYear(), $now->getMonth(), 1))
                ->addMonths(1)
                ->subDays(1)
                ->toCarbon()
                ->endOfDay()
                ->setTimezone('Asia/Tehran');

            return [$start, $end, $filter];
        }

        $day = Carbon::now('Asia/Tehran');

        return [$day->copy()->startOfDay(), $day->copy()->endOfDay(), 'today'];
    }

    protected static function normalizeJalaliYmd(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace(['/', '.'], '-', $raw);
        if (! preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $raw)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $raw));

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    protected static function parseJalaliDayStart(?string $value): ?Carbon
    {
        $ymd = self::normalizeJalaliYmd($value);
        if (! $ymd) {
            return null;
        }
        try {
            return Jalalian::fromFormat('Y-m-d', $ymd)->toCarbon()->startOfDay()->setTimezone('Asia/Tehran');
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function parseJalaliDayEnd(?string $value): ?Carbon
    {
        $ymd = self::normalizeJalaliYmd($value);
        if (! $ymd) {
            return null;
        }
        try {
            return Jalalian::fromFormat('Y-m-d', $ymd)->toCarbon()->endOfDay()->setTimezone('Asia/Tehran');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendSaleEvents(
        array &$events,
        int $atelierId,
        Carbon $start,
        Carbon $end,
        string $startString,
        string $endString
    ): void {
        $query = Purchase::query()->forAtelier($atelierId);
        if ($start->toDateString() === $end->toDateString()) {
            $query->whereDate('created_at', $start->toDateString());
        } else {
            $query->whereBetween('created_at', [$startString, $endString]);
        }

        $purchases = $query
            ->with(['purchasedProducts'])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        foreach ($purchases as $purchase) {
            $total = round((float) ($purchase->total_amount ?? 0), 2);
            if ($total <= 0) {
                $total = round((float) $purchase->remainingLineSalesTotal(), 2);
            }
            if ($total < 0.01) {
                continue;
            }

            $cash = round((float) ($purchase->cash_amount ?? 0), 2);
            $card = round((float) ($purchase->card_amount ?? 0), 2);
            $type = (string) ($purchase->payment_type ?? 'cash');
            $phone = trim((string) ($purchase->phone ?? ''));
            $party = self::partyFromPhone($phone);
            $titleBase = $phone !== '' ? 'فروش '.$phone : 'فروش #'.$purchase->id;
            $at = optional($purchase->created_at)?->timezone('Asia/Tehran');

            if ($type === 'debt') {
                $events[] = self::event(
                    'sale_debt_'.$purchase->id,
                    'in',
                    'sale_debt',
                    'debt',
                    'نسیه جدید — '.$titleBase,
                    'ثبت طلب مشتری (هنوز نقد نشده)',
                    $total,
                    0,
                    0,
                    $at,
                    '/admin/purchas',
                    $party
                );
                continue;
            }

            if ($type === 'cheque' || $purchase->cheque_id) {
                $events[] = self::event(
                    'sale_cheque_'.$purchase->id,
                    'in',
                    'sale_cheque',
                    'cheque',
                    'فروش چکی — '.$titleBase,
                    'دریافت چک (تا وصول نقد نیست)',
                    $total,
                    $cash,
                    $card,
                    $at,
                    '/admin/cheques',
                    $party
                );
                if ($cash + $card >= 0.01) {
                    $method = self::cashCardMethod($cash, $card);
                    $events[] = self::event(
                        'sale_cheque_part_'.$purchase->id,
                        'in',
                        'sale_'.$method,
                        $method,
                        'بخش نقد/کارت فروش چکی — '.$titleBase,
                        null,
                        round($cash + $card, 2),
                        $cash,
                        $card,
                        $at,
                        '/admin/purchas',
                        $party
                    );
                }
                continue;
            }

            if ($type === 'installment') {
                $immediate = round($cash + $card, 2);
                if ($immediate >= 0.01) {
                    $method = self::cashCardMethod($cash, $card);
                    $events[] = self::event(
                        'sale_installment_down_'.$purchase->id,
                        'in',
                        'sale_'.$method,
                        $method,
                        'پیش‌قسط فروش اقساطی — '.$titleBase,
                        'قسط اول / پرداخت اولیه',
                        $immediate,
                        $cash,
                        $card,
                        $at,
                        '/admin/installments',
                        $party
                    );
                }
                $events[] = self::event(
                    'sale_installment_'.$purchase->id,
                    'in',
                    'sale_installment',
                    'installment',
                    'فروش اقساطی — '.$titleBase,
                    'ثبت فروش اقساطی',
                    $total,
                    $cash,
                    $card,
                    $at,
                    '/admin/installments',
                    $party
                );
                continue;
            }

            $method = $type === 'mixed' || $type === 'split'
                ? 'mixed'
                : self::cashCardMethod($cash, $card);
            if ($type === 'cash' && $cash < 0.01 && $card >= 0.01) {
                $method = 'card';
            }
            $label = $method === 'mixed' ? 'فروش ترکیبی' : ($method === 'card' ? 'فروش کارتی' : 'فروش نقدی');
            $events[] = self::event(
                'sale_'.$purchase->id,
                'in',
                'sale_'.$method,
                $method,
                $label.' — '.$titleBase,
                null,
                max($total, round($cash + $card, 2)),
                $cash,
                $card,
                $at,
                '/admin/purchas',
                $party
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendDebtSettlementEvents(
        array &$events,
        int $atelierId,
        string $startString,
        string $endString
    ): void {
        if (! Schema::hasColumn('purchases', 'is_debt_settled')) {
            return;
        }

        if (Schema::hasTable('purchase_debt_payments')) {
            $payments = PurchaseDebtPayment::query()
                ->with(['purchase', 'shopAccount:id,name'])
                ->whereBetween('paid_at', [$startString, $endString])
                ->whereHas('purchase', function ($q) use ($atelierId) {
                    $q->forAtelier($atelierId)->where('payment_type', 'debt');
                })
                ->orderByDesc('paid_at')
                ->limit(300)
                ->get();

            foreach ($payments as $payment) {
                $purchase = $payment->purchase;
                $cash = round((float) $payment->cash_amount, 2);
                $card = round((float) $payment->card_amount, 2);
                $amount = round($cash + $card, 2);
                if ($amount < 0.01) {
                    continue;
                }
                $method = self::cashCardMethod($cash, $card);
                $phone = trim((string) ($purchase->phone ?? ''));
                $account = $payment->shopAccount?->name;
                $subtitle = trim(
                    ($account ? 'حساب '.$account : '')
                    .($payment->note ? ' — '.$payment->note : '')
                );
                $events[] = self::event(
                    'debt_pay_'.$payment->id,
                    'in',
                    'debt_settle',
                    $method,
                    'تسویه نسیه'.($phone !== '' ? ' — '.$phone : ' — فاکتور #'.($purchase->id ?? '')),
                    $subtitle !== '' ? $subtitle : 'وصول طلب نسیه',
                    $amount,
                    $cash,
                    $card,
                    optional($payment->paid_at)?->timezone('Asia/Tehran'),
                    $phone !== '' ? '/admin/purchas/customer/'.$phone : '/admin/purchase-debts',
                    self::partyFromPhone($phone)
                );
            }
        }

        $legacyQuery = Purchase::query()
            ->forAtelier($atelierId)
            ->where('payment_type', 'debt')
            ->where('is_debt_settled', true)
            ->whereNotNull('debt_settled_at')
            ->whereBetween('debt_settled_at', [$startString, $endString]);

        if (Schema::hasTable('purchase_debt_payments')) {
            $legacyQuery->whereDoesntHave('debtPayments');
        }

        foreach ($legacyQuery->limit(100)->get() as $purchase) {
            $cash = round((float) ($purchase->debt_settled_cash_amount ?? 0), 2);
            $card = round((float) ($purchase->debt_settled_card_amount ?? 0), 2);
            $amount = round($cash + $card, 2);
            if ($amount < 0.01) {
                continue;
            }
            $method = self::cashCardMethod($cash, $card);
            $phone = trim((string) ($purchase->phone ?? ''));
            $events[] = self::event(
                'debt_legacy_'.$purchase->id,
                'in',
                'debt_settle',
                $method,
                'تسویه نسیه'.($phone !== '' ? ' — '.$phone : ' — فاکتور #'.$purchase->id),
                'تسویه کامل',
                $amount,
                $cash,
                $card,
                optional($purchase->debt_settled_at)?->timezone('Asia/Tehran'),
                $phone !== '' ? '/admin/purchas/customer/'.$phone : '/admin/purchase-debts',
                self::partyFromPhone($phone)
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendInstallmentEvents(
        array &$events,
        int $atelierId,
        string $startString,
        string $endString
    ): void {
        if (! Schema::hasTable('installments')) {
            return;
        }

        $rows = Installment::query()
            ->with(['purchase'])
            ->where('is_paid', true)
            ->where('installment_number', '>', 1)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$startString, $endString])
            ->whereHas('purchase', fn ($q) => $q->forAtelier($atelierId))
            ->orderByDesc('paid_at')
            ->limit(200)
            ->get();

        foreach ($rows as $row) {
            $amount = round((float) $row->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $phone = trim((string) ($row->purchase->phone ?? ''));
            $events[] = self::event(
                'installment_'.$row->id,
                'in',
                'installment_collect',
                'cash',
                'وصول قسط '.((int) $row->installment_number).($phone !== '' ? ' — '.$phone : ''),
                'فروش اقساطی #'.((int) ($row->purchase_id ?? 0)),
                $amount,
                $amount,
                0,
                optional($row->paid_at)?->timezone('Asia/Tehran'),
                '/admin/installments',
                self::partyFromPhone($phone)
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendChequeEvents(
        array &$events,
        int $atelierId,
        string $startString,
        string $endString
    ): void {
        if (! Schema::hasTable('cheques')) {
            return;
        }

        $cleared = Cheque::query()
            ->where('atelier_id', $atelierId)
            ->where('status', Cheque::STATUS_CLEARED)
            ->whereNotNull('cleared_at')
            ->whereBetween('cleared_at', [$startString, $endString])
            ->orderByDesc('cleared_at')
            ->limit(200)
            ->get();

        foreach ($cleared as $cheque) {
            $amount = round((float) $cheque->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $received = $cheque->type === Cheque::TYPE_RECEIVED;
            $payee = trim((string) ($cheque->payee ?: ($cheque->title ?: '')));
            $events[] = self::event(
                'cheque_clear_'.$cheque->id,
                $received ? 'in' : 'out',
                $received ? 'cheque_clear_in' : 'cheque_clear_out',
                'cheque',
                ($received ? 'وصول چک دریافتی' : 'وصول چک صادره').' — '.$cheque->cheque_number,
                trim(($cheque->bank_name ? $cheque->bank_name.' · ' : '').$payee),
                $amount,
                $received ? $amount : 0,
                0,
                optional($cheque->cleared_at)?->timezone('Asia/Tehran'),
                '/admin/cheques',
                $payee !== '' ? $payee : null
            );
        }

        $transferred = Cheque::query()
            ->where('atelier_id', $atelierId)
            ->where('type', Cheque::TYPE_RECEIVED)
            ->where('status', Cheque::STATUS_TRANSFERRED)
            ->whereNotNull('transferred_at')
            ->whereBetween('transferred_at', [$startString, $endString])
            ->orderByDesc('transferred_at')
            ->limit(100)
            ->get();

        foreach ($transferred as $cheque) {
            $amount = round((float) $cheque->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $payee = trim((string) ($cheque->payee ?: ($cheque->title ?: '')));
            $events[] = self::event(
                'cheque_transfer_'.$cheque->id,
                'out',
                'cheque_transfer',
                'cheque',
                'ظهرنویسی چک — '.$cheque->cheque_number,
                'پرداخت با چک دریافتی (بدون نقد)',
                $amount,
                0,
                0,
                optional($cheque->transferred_at)?->timezone('Asia/Tehran'),
                '/admin/cheques',
                $payee !== '' ? $payee : null
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendDocumentOutflowEvents(
        array &$events,
        int $atelierId,
        string $fromDate,
        string $toDate
    ): void {
        if (! DocumentPaymentService::supportsSplits()) {
            self::appendLegacyDocumentOutflows($events, $atelierId, $fromDate, $toDate);

            return;
        }

        $payments = DocumentPayment::query()
            ->with([
                'invoice:id,title,amount,date,beneficiary_id,user_name',
                'invoice.beneficiary:id,phone,name',
                'expense:id,title,amount,date,type,beneficiary_id,user_name',
                'expense.beneficiary:id,phone,name',
                'shopAccount:id,name',
                'cheque:id,cheque_number,status',
            ])
            ->where('atelier_id', $atelierId)
            ->where('settled', true)
            ->where(function ($q) use ($fromDate, $toDate) {
                $q->where(function ($inner) use ($fromDate, $toDate) {
                    $inner->whereIn('method', [DocumentPayment::METHOD_ACCOUNT, DocumentPayment::METHOD_ADVANCE])
                        ->whereDate('created_at', '>=', $fromDate)
                        ->whereDate('created_at', '<=', $toDate);
                })->orWhere(function ($inner) use ($fromDate, $toDate) {
                    $inner->where('method', DocumentPayment::METHOD_CREDIT)
                        ->whereDate('updated_at', '>=', $fromDate)
                        ->whereDate('updated_at', '<=', $toDate);
                })->orWhere(function ($inner) use ($fromDate, $toDate) {
                    $inner->where('method', DocumentPayment::METHOD_CHEQUE)
                        ->whereHas('cheque', function ($cheque) use ($fromDate, $toDate) {
                            $cheque->where(function ($c) use ($fromDate, $toDate) {
                                $c->where('status', Cheque::STATUS_CLEARED)
                                    ->whereDate('cleared_at', '>=', $fromDate)
                                    ->whereDate('cleared_at', '<=', $toDate);
                            })->orWhere(function ($c) use ($fromDate, $toDate) {
                                $c->where('status', Cheque::STATUS_TRANSFERRED)
                                    ->whereDate('transferred_at', '>=', $fromDate)
                                    ->whereDate('transferred_at', '<=', $toDate);
                            });
                        });
                });
            })
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        foreach ($payments as $payment) {
            $amount = round((float) $payment->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            // چک‌های وصول‌شده جدا در لیست چک آمده‌اند؛ از دوبل شدن جلوگیری
            if ($payment->method === DocumentPayment::METHOD_CHEQUE) {
                continue;
            }
            $isInvoice = (int) ($payment->invoice_id ?? 0) > 0;
            $doc = $isInvoice ? $payment->invoice : $payment->expense;
            if (! $isInvoice && $payment->expense && ($payment->expense->type ?? '') !== 'جاری') {
                continue;
            }
            $method = $payment->method === DocumentPayment::METHOD_ADVANCE
                ? 'advance'
                : ($payment->method === DocumentPayment::METHOD_CREDIT ? 'credit' : 'cash');
            $title = $isInvoice
                ? ('پرداخت فاکتور — '.($doc->title ?? '#'.$payment->invoice_id))
                : ('پرداخت هزینه — '.($doc->title ?? '#'.$payment->expense_id));
            $account = $payment->shopAccount?->name;
            $at = $payment->created_at?->timezone('Asia/Tehran');
            if ($payment->method === DocumentPayment::METHOD_CREDIT && $payment->updated_at) {
                $at = $payment->updated_at->timezone('Asia/Tehran');
            }
            $events[] = self::event(
                'doc_pay_'.$payment->id,
                'out',
                $isInvoice ? 'invoice_pay' : 'expense_pay',
                $method,
                $title,
                $account ? 'از '.$account : DocumentPayment::labelFor((string) $payment->method),
                $amount,
                $method === 'cash' ? $amount : 0,
                0,
                $at,
                $isInvoice ? '/admin/invoices' : '/admin/expenses',
                self::partyFromDocument($doc)
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendLegacyDocumentOutflows(
        array &$events,
        int $atelierId,
        string $fromDate,
        string $toDate
    ): void {
        $expenses = Expense::query()
            ->with(['beneficiary:id,phone,name'])
            ->where('atelier_id', $atelierId)
            ->where('type', 'جاری')
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->orderByDesc('id')
            ->limit(150)
            ->get();

        foreach ($expenses as $expense) {
            $amount = round((float) $expense->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $events[] = self::event(
                'expense_legacy_'.$expense->id,
                'out',
                'expense_pay',
                'cash',
                'هزینه — '.($expense->title ?: '#'.$expense->id),
                null,
                $amount,
                $amount,
                0,
                $expense->date ? Carbon::parse($expense->date, 'Asia/Tehran')->endOfDay() : null,
                '/admin/expenses',
                self::partyFromDocument($expense)
            );
        }

        $invoices = Invoice::query()
            ->with(['beneficiary:id,phone,name'])
            ->where('atelier_id', $atelierId)
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->orderByDesc('id')
            ->limit(150)
            ->get();

        foreach ($invoices as $invoice) {
            $amount = round((float) $invoice->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $events[] = self::event(
                'invoice_legacy_'.$invoice->id,
                'out',
                'invoice_pay',
                'cash',
                'فاکتور — '.($invoice->title ?: '#'.$invoice->id),
                null,
                $amount,
                $amount,
                0,
                $invoice->date ? Carbon::parse($invoice->date, 'Asia/Tehran')->endOfDay() : null,
                '/admin/invoices',
                self::partyFromDocument($invoice)
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    protected static function buildSummary(array $events): array
    {
        $bucket = fn () => ['count' => 0, 'amount' => 0.0];
        $in = [
            'total' => $bucket(),
            'cash' => $bucket(),
            'card' => $bucket(),
            'mixed' => $bucket(),
            'debt_settle' => $bucket(),
            'cheque' => $bucket(),
            'installment' => $bucket(),
            'other' => $bucket(),
        ];
        $out = [
            'total' => $bucket(),
            'cash' => $bucket(),
            'cheque' => $bucket(),
            'expense' => $bucket(),
            'invoice' => $bucket(),
            'other' => $bucket(),
        ];

        $add = function (array &$target, string $key, float $amount) {
            if (! isset($target[$key])) {
                $target[$key] = ['count' => 0, 'amount' => 0.0];
            }
            $target[$key]['count']++;
            $target[$key]['amount'] = round($target[$key]['amount'] + $amount, 2);
        };

        foreach ($events as $event) {
            $amount = round((float) ($event['amount'] ?? 0), 2);
            $kind = (string) ($event['kind'] ?? '');
            $method = (string) ($event['method'] ?? '');
            $direction = (string) ($event['direction'] ?? '');

            if ($direction === 'in') {
                // نسیه/اقساطی جدید و چکِ وصول‌نشده را در جمع نقد دریافتی نیاور
                if (in_array($kind, ['sale_debt', 'sale_installment', 'sale_cheque'], true)) {
                    continue;
                }
                $add($in, 'total', $amount);
                if ($kind === 'debt_settle') {
                    // فقط در سطل تسویه نسیه — با نقد/کارت فروش قاطی نشود
                    $add($in, 'debt_settle', $amount);
                } elseif ($kind === 'cheque_clear_in') {
                    $add($in, 'cheque', $amount);
                } elseif ($kind === 'installment_collect') {
                    $add($in, 'installment', $amount);
                } elseif ($method === 'mixed') {
                    $add($in, 'mixed', $amount);
                } elseif ($method === 'card') {
                    $add($in, 'card', $amount);
                } elseif ($method === 'cash') {
                    $add($in, 'cash', $amount);
                } elseif ($method === 'cheque') {
                    $add($in, 'cheque', $amount);
                } else {
                    $add($in, 'other', $amount);
                }
            } else {
                $add($out, 'total', $amount);
                if ($kind === 'expense_pay') {
                    $add($out, 'expense', $amount);
                } elseif ($kind === 'invoice_pay') {
                    $add($out, 'invoice', $amount);
                }
                if ($method === 'cheque' || $kind === 'cheque_clear_out' || $kind === 'cheque_transfer') {
                    $add($out, 'cheque', $amount);
                } elseif ($method === 'cash' || $method === 'account') {
                    $add($out, 'cash', $amount);
                } else {
                    $add($out, 'other', $amount);
                }
            }
        }

        return [
            'inflow' => $in,
            'outflow' => $out,
            'net' => round(($in['total']['amount'] ?? 0) - ($out['total']['amount'] ?? 0), 2),
        ];
    }

    protected static function cashCardMethod(float $cash, float $card): string
    {
        if ($cash >= 0.01 && $card >= 0.01) {
            return 'mixed';
        }
        if ($card >= 0.01 && $cash < 0.01) {
            return 'card';
        }

        return 'cash';
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * نام طرف‌حساب از ذینفع فاکتور/هزینه.
     */
    protected static function partyFromDocument($doc): ?string
    {
        if (! $doc) {
            return null;
        }
        $beneficiary = $doc->relationLoaded('beneficiary') ? $doc->beneficiary : null;
        $name = trim((string) ($beneficiary->name ?? ''));
        if ($name !== '') {
            return $name;
        }
        $phone = trim((string) ($beneficiary->phone ?? ''));
        if ($phone !== '') {
            return $phone;
        }
        $userName = trim((string) ($doc->user_name ?? ''));

        return $userName !== '' ? $userName : null;
    }

    protected static function partyFromPhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone !== '' ? $phone : null;
    }

    protected static function event(
        string $id,
        string $direction,
        string $kind,
        string $method,
        string $title,
        ?string $subtitle,
        float $amount,
        float $cash,
        float $card,
        $at,
        ?string $href,
        ?string $party = null
    ): array {
        $carbon = null;
        if ($at instanceof Carbon) {
            $carbon = $at->copy()->setTimezone('Asia/Tehran');
        } elseif (is_string($at) && $at !== '') {
            try {
                $carbon = Carbon::parse($at, 'Asia/Tehran');
            } catch (\Throwable $e) {
                $carbon = null;
            }
        }

        $partyLabel = trim((string) $party);

        return [
            'id' => $id,
            'direction' => $direction,
            'kind' => $kind,
            'method' => $method,
            'method_label' => self::methodLabel($method),
            'title' => $title,
            'subtitle' => $subtitle,
            'party' => $partyLabel !== '' ? $partyLabel : null,
            'amount' => round($amount, 2),
            'cash_amount' => round($cash, 2),
            'card_amount' => round($card, 2),
            'at' => $carbon ? $carbon->toDateTimeString() : null,
            'at_jalali' => $carbon ? Jalalian::fromCarbon($carbon)->format('Y/m/d H:i') : null,
            'href' => $href,
        ];
    }

    protected static function methodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'نقد',
            'card' => 'کارت',
            'mixed' => 'ترکیبی',
            'cheque' => 'چک',
            'debt' => 'نسیه',
            'installment' => 'اقساط',
            'credit' => 'نسیه تأمین‌کننده',
            'advance' => 'پیش‌پرداخت',
            default => $method,
        };
    }
}
