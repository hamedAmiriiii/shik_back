<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\DailyShopReconciliation;
use App\Models\DailyShopReconciliationAccountDeposit;
use App\Models\DocumentPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\ManualTrade;
use App\Models\PurchaseDebtPayment;
use App\Models\PurchaseItemReturn;
use App\Models\ShopAccount;
use App\Models\ShopAccountBalanceAdjustment;
use App\Models\ShopAccountTransfer;
use App\Models\ShopPartnerSettlement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

/**
 * گردش کامل ورودی/خروجی یک حساب فروشگاه، تنخواه یا صندوق.
 */
class ShopAccountTransactionService
{
    /**
     * @return array{
     *   account: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   events: array<int, array<string, mixed>>
     * }
     */
    public static function history(ShopAccount $account, int $limit = 500): array
    {
        $atelierId = (int) $account->atelier_id;
        $accountId = (int) $account->id;
        $events = [];

        self::appendDeposits($events, $atelierId, $account);
        self::appendTransfers($events, $atelierId, $accountId);
        self::appendDocumentPayments($events, $atelierId, $accountId);
        self::appendLegacyDocuments($events, $atelierId, $accountId, 'expenses');
        self::appendLegacyDocuments($events, $atelierId, $accountId, 'invoices');
        self::appendManualTrades($events, $atelierId, $accountId);
        self::appendChequeClears($events, $atelierId, $accountId);
        self::appendDebtCollections($events, $atelierId, $accountId);
        self::appendSaleReturnRefunds($events, $atelierId, $accountId);
        self::appendPartnerSettlements($events, $atelierId, $accountId);
        self::appendAdjustments($events, $atelierId, $accountId);

        usort($events, function (array $a, array $b) {
            $cmp = strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        $running = 0.0;
        $inTotal = 0.0;
        $outTotal = 0.0;
        foreach ($events as &$event) {
            $signed = (float) ($event['signed_amount'] ?? 0);
            $running = round($running + $signed, 2);
            $event['balance_after'] = $running;
            if ($signed >= 0) {
                $inTotal = round($inTotal + $signed, 2);
            } else {
                $outTotal = round($outTotal + abs($signed), 2);
            }
        }
        unset($event);

        if (count($events) > $limit) {
            $events = array_slice($events, -$limit);
        }

        // نمایش از جدید به قدیم
        $events = array_reverse($events);

        $breakdown = ShopAccountBalanceService::breakdown($atelierId, [$accountId]);
        $currentBalance = (float) ($breakdown[$accountId]['balance'] ?? 0);

        return [
            'account' => [
                'id' => $accountId,
                'name' => $account->name,
                'type' => $account->type ?: ShopAccount::TYPE_SHOP,
                'type_label' => $account->typeLabel(),
                'is_till' => $account->isTill(),
                'balance' => round($currentBalance, 2),
            ],
            'summary' => [
                'in_total' => $inTotal,
                'out_total' => $outTotal,
                'net' => round($inTotal - $outTotal, 2),
                'events_count' => count($events),
                'computed_balance' => $running,
                'current_balance' => round($currentBalance, 2),
            ],
            'events' => array_values($events),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendDeposits(array &$events, int $atelierId, ShopAccount $account): void
    {
        $accountId = (int) $account->id;

        if (Schema::hasTable('daily_shop_reconciliation_account_deposits')) {
            $rows = DailyShopReconciliationAccountDeposit::query()
                ->with(['reconciliation:id,atelier_id,date'])
                ->where('shop_account_id', $accountId)
                ->where('amount', '>', 0)
                ->whereHas('reconciliation', fn ($q) => $q->where('atelier_id', $atelierId))
                ->orderByDesc('id')
                ->limit(1000)
                ->get();

            foreach ($rows as $row) {
                $recon = $row->reconciliation;
                $carbon = self::toTehranEndOfDay($recon?->date);
                $amount = round((float) $row->amount, 2);
                if ($amount < 0.01) {
                    continue;
                }
                $events[] = self::event(
                    'deposit:'.$row->id,
                    'deposit',
                    'واریز تطبیق روزانه',
                    'in',
                    $amount,
                    'واریز تطبیق روزانه',
                    $carbon ? 'تاریخ '.$carbon->toDateString() : null,
                    $carbon,
                    '/admin/daily-reconciliation'
                );
            }
        }

        // ستون‌های قدیمی بدون ردیف جدید
        if (! Schema::hasTable('daily_shop_reconciliations')) {
            return;
        }
        $slot = $account->legacy_slot ?? null;
        if (! in_array($slot, [ShopAccount::LEGACY_ACCOUNT_1, ShopAccount::LEGACY_ACCOUNT_2], true)) {
            return;
        }
        $column = $slot === ShopAccount::LEGACY_ACCOUNT_1 ? 'deposit_account_1' : 'deposit_account_2';
        $legacy = DailyShopReconciliation::query()
            ->where('atelier_id', $atelierId)
            ->where($column, '>', 0)
            ->when(
                Schema::hasTable('daily_shop_reconciliation_account_deposits'),
                function ($q) use ($accountId) {
                    $q->whereDoesntHave('accountDeposits', function ($inner) use ($accountId) {
                        $inner->where('shop_account_id', $accountId)->where('amount', '>', 0);
                    });
                }
            )
            ->orderByDesc('date')
            ->limit(500)
            ->get();

        foreach ($legacy as $recon) {
            $amount = round((float) ($recon->{$column} ?? 0), 2);
            if ($amount < 0.01) {
                continue;
            }
            $events[] = self::event(
                'deposit_legacy:'.$recon->id,
                'deposit',
                'واریز تطبیق روزانه',
                'in',
                $amount,
                'واریز تطبیق روزانه (قدیمی)',
                null,
                self::toTehranEndOfDay($recon->date),
                '/admin/daily-reconciliation'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendTransfers(array &$events, int $atelierId, int $accountId): void
    {
        if (! Schema::hasTable('shop_account_transfers')) {
            return;
        }

        $rows = ShopAccountTransfer::query()
            ->forAtelier($atelierId)
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->where(function ($q) use ($accountId) {
                $q->where('from_shop_account_id', $accountId)
                    ->orWhere('to_shop_account_id', $accountId);
            })
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        foreach ($rows as $row) {
            $amount = round((float) $row->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $isOut = (int) $row->from_shop_account_id === $accountId;
            $other = $isOut ? $row->toAccount : $row->fromAccount;
            $rawCreated = $row->getAttributes()['created_at'] ?? null;
            $rawDate = $row->getAttributes()['date'] ?? null;
            $carbon = null;
            if ($rawCreated) {
                try {
                    $carbon = Carbon::parse($rawCreated)->setTimezone('Asia/Tehran');
                } catch (\Throwable $e) {
                    $carbon = null;
                }
            }
            if (! $carbon && $rawDate) {
                try {
                    $carbon = Carbon::parse($rawDate, 'Asia/Tehran')->endOfDay();
                } catch (\Throwable $e) {
                    $carbon = null;
                }
            }
            $title = trim((string) ($row->title ?? ''));
            if ($title === '') {
                $title = $isOut ? 'شارژ تنخواه' : 'دریافت شارژ';
            }
            $events[] = self::event(
                'transfer:'.$row->id.':'.($isOut ? 'out' : 'in'),
                $isOut ? 'transfer_out' : 'transfer_in',
                $isOut ? 'شارژ تنخواه' : 'دریافت انتقال',
                $isOut ? 'out' : 'in',
                $amount,
                $title,
                $other?->name ? ($isOut ? 'به '.$other->name : 'از '.$other->name) : null,
                $carbon,
                '/admin/petty-cash'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendDocumentPayments(array &$events, int $atelierId, int $accountId): void
    {
        if (! Schema::hasTable('document_payments') || ! DocumentPaymentService::supportsSplits()) {
            return;
        }

        $rows = DocumentPayment::query()
            ->with(['invoice:id,title', 'expense:id,title,type'])
            ->where('atelier_id', $atelierId)
            ->where('shop_account_id', $accountId)
            ->where('settled', true)
            ->whereIn('method', [DocumentPayment::METHOD_ACCOUNT, DocumentPayment::METHOD_ADVANCE])
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        foreach ($rows as $payment) {
            $amount = round((float) $payment->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $isInvoice = (int) ($payment->invoice_id ?? 0) > 0;
            if (! $isInvoice && $payment->expense && ($payment->expense->type ?? '') !== 'جاری') {
                continue;
            }
            $doc = $isInvoice ? $payment->invoice : $payment->expense;
            $title = $isInvoice
                ? ('پرداخت فاکتور — '.($doc->title ?? '#'.$payment->invoice_id))
                : ('پرداخت هزینه — '.($doc->title ?? '#'.$payment->expense_id));
            $at = $payment->created_at?->timezone('Asia/Tehran');
            $events[] = self::event(
                'doc_pay:'.$payment->id,
                $isInvoice ? 'invoice' : 'expense',
                $isInvoice ? 'پرداخت فاکتور' : 'پرداخت هزینه',
                'out',
                $amount,
                $title,
                DocumentPayment::labelFor((string) $payment->method),
                $at,
                $isInvoice ? '/admin/invoices' : '/admin/expenses'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendLegacyDocuments(array &$events, int $atelierId, int $accountId, string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'shop_account_id')) {
            return;
        }

        $model = $table === 'expenses' ? Expense::class : Invoice::class;
        $fk = $table === 'expenses' ? 'expense_id' : 'invoice_id';
        $query = $model::query()
            ->where('atelier_id', $atelierId)
            ->where('shop_account_id', $accountId)
            ->when(
                Schema::hasColumn($table, 'payment_status'),
                fn ($q) => $q->where('payment_status', 'paid')
            );

        if ($table === 'expenses') {
            $query->where('type', 'جاری');
        }

        if (Schema::hasTable('document_payments')) {
            $query->whereNotIn('id', function ($q) use ($fk) {
                $q->select($fk)->from('document_payments')->whereNotNull($fk);
            });
        }

        foreach ($query->orderByDesc('id')->limit(500)->get() as $doc) {
            $amount = round((float) $doc->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $carbon = null;
            if (! empty($doc->date)) {
                try {
                    $carbon = Carbon::parse((string) $doc->date, 'Asia/Tehran')->endOfDay();
                } catch (\Throwable $e) {
                    $carbon = null;
                }
            }
            $isInvoice = $table === 'invoices';
            $events[] = self::event(
                ($isInvoice ? 'invoice_legacy:' : 'expense_legacy:').$doc->id,
                $isInvoice ? 'invoice' : 'expense',
                $isInvoice ? 'پرداخت فاکتور' : 'پرداخت هزینه',
                'out',
                $amount,
                ($isInvoice ? 'فاکتور — ' : 'هزینه — ').($doc->title ?: '#'.$doc->id),
                null,
                $carbon,
                $isInvoice ? '/admin/invoices' : '/admin/expenses'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendManualTrades(array &$events, int $atelierId, int $accountId): void
    {
        if (! ManualTrade::tableReady() || ! Schema::hasColumn('manual_trades', 'shop_account_id')) {
            return;
        }

        $rows = ManualTrade::query()
            ->where('atelier_id', $atelierId)
            ->where('shop_account_id', $accountId)
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        foreach ($rows as $trade) {
            $amount = round((float) $trade->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $isSale = ($trade->type ?? '') === ManualTrade::TYPE_SALE;
            $carbon = null;
            if (! empty($trade->date)) {
                try {
                    $carbon = Carbon::parse((string) $trade->date, 'Asia/Tehran')->endOfDay();
                } catch (\Throwable $e) {
                    $carbon = $trade->created_at?->timezone('Asia/Tehran');
                }
            } else {
                $carbon = $trade->created_at?->timezone('Asia/Tehran');
            }
            $events[] = self::event(
                'trade:'.$trade->id,
                $isSale ? 'manual_sale' : 'manual_purchase',
                $isSale ? 'معامله دستی فروش' : 'معامله دستی خرید',
                $isSale ? 'in' : 'out',
                $amount,
                trim((string) ($trade->title ?? '')) ?: ($isSale ? 'فروش دستی' : 'خرید دستی'),
                null,
                $carbon,
                null
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendChequeClears(array &$events, int $atelierId, int $accountId): void
    {
        if (! Schema::hasTable('cheques') || ! Schema::hasColumn('cheques', 'shop_account_id')) {
            return;
        }

        $rows = Cheque::query()
            ->where('atelier_id', $atelierId)
            ->where('type', Cheque::TYPE_RECEIVED)
            ->where('status', Cheque::STATUS_CLEARED)
            ->where('shop_account_id', $accountId)
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        foreach ($rows as $cheque) {
            $amount = round((float) $cheque->amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $events[] = self::event(
                'cheque_clear:'.$cheque->id,
                'cheque_clear',
                'وصول چک',
                'in',
                $amount,
                'وصول چک — '.$cheque->cheque_number,
                trim(($cheque->bank_name ? $cheque->bank_name.' · ' : '').($cheque->payee ?: '')),
                optional($cheque->cleared_at)?->timezone('Asia/Tehran'),
                '/admin/cheques'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendDebtCollections(array &$events, int $atelierId, int $accountId): void
    {
        if (! Schema::hasTable('purchase_debt_payments')
            || ! Schema::hasColumn('purchase_debt_payments', 'shop_account_id')
        ) {
            return;
        }

        $rows = PurchaseDebtPayment::query()
            ->with(['purchase:id,phone,atelier_id'])
            ->where('shop_account_id', $accountId)
            ->whereHas('purchase', fn ($q) => $q->where('atelier_id', $atelierId))
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        foreach ($rows as $payment) {
            $amount = round((float) $payment->cash_amount + (float) $payment->card_amount, 2);
            if ($amount < 0.01) {
                continue;
            }
            $phone = trim((string) ($payment->purchase->phone ?? ''));
            $events[] = self::event(
                'debt_pay:'.$payment->id,
                'debt_collection',
                'وصول نسیه',
                'in',
                $amount,
                'وصول نسیه'.($phone !== '' ? ' — '.$phone : ''),
                $payment->note,
                optional($payment->paid_at)?->timezone('Asia/Tehran'),
                $phone !== '' ? '/admin/purchas/customer/'.$phone : '/admin/purchase-debts'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendSaleReturnRefunds(array &$events, int $atelierId, int $accountId): void
    {
        if (! Schema::hasTable('purchase_item_returns')
            || ! Schema::hasColumn('purchase_item_returns', 'shop_account_id')
        ) {
            return;
        }

        $query = PurchaseItemReturn::query()
            ->where('atelier_id', $atelierId)
            ->where('shop_account_id', $accountId);

        if (Schema::hasColumn('purchase_item_returns', 'card_refund_destination')) {
            $query->where('card_refund_destination', 'shop_account');
        }

        foreach ($query->orderByDesc('id')->limit(300)->get() as $row) {
            $amount = Schema::hasColumn('purchase_item_returns', 'card_refunded')
                ? round((float) ($row->card_refunded ?? 0), 2)
                : round((float) ($row->return_sale_total ?? 0), 2);
            if ($amount < 0.01) {
                continue;
            }
            $events[] = self::event(
                'sale_return:'.$row->id,
                'sale_return',
                'برگشت فروش',
                'out',
                $amount,
                'برگشت مبلغ به مشتری',
                null,
                optional($row->created_at)?->timezone('Asia/Tehran'),
                '/admin/purchas'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendPartnerSettlements(array &$events, int $atelierId, int $accountId): void
    {
        if (! Schema::hasTable('shop_partner_settlements')
            || ! Schema::hasColumn('shop_partner_settlements', 'shop_account_id')
        ) {
            return;
        }

        $rows = ShopPartnerSettlement::query()
            ->where('atelier_id', $atelierId)
            ->where('shop_account_id', $accountId)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        foreach ($rows as $row) {
            $amount = round((float) ($row->total_distributed ?? 0), 2);
            if ($amount < 0.01) {
                continue;
            }
            $events[] = self::event(
                'partner:'.$row->id,
                'partner_settlement',
                'تقسیم سود شرکا',
                'out',
                $amount,
                'برداشت تقسیم سود',
                null,
                optional($row->created_at)?->timezone('Asia/Tehran'),
                '/admin/partners'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    protected static function appendAdjustments(array &$events, int $atelierId, int $accountId): void
    {
        if (! Schema::hasTable('shop_account_balance_adjustments')) {
            return;
        }

        $rows = ShopAccountBalanceAdjustment::query()
            ->where('atelier_id', $atelierId)
            ->where('shop_account_id', $accountId)
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        foreach ($rows as $row) {
            $signed = round((float) $row->amount, 2);
            if (abs($signed) < 0.01) {
                continue;
            }
            $direction = $signed >= 0 ? 'in' : 'out';
            $carbon = $row->created_at?->timezone('Asia/Tehran');
            if (! $carbon) {
                $carbon = self::toTehranEndOfDay($row->date);
            }
            $events[] = self::event(
                'adjust:'.$row->id,
                'adjust',
                'اصلاح موجودی',
                $direction,
                abs($signed),
                'اصلاح دستی موجودی',
                $row->description,
                $carbon,
                null,
                $signed
            );
        }
    }

    /**
     * تاریخ مدل/رشته را به Carbon پایان‌روز تهران تبدیل می‌کند.
     */
    protected static function toTehranEndOfDay($date): ?Carbon
    {
        if ($date instanceof Carbon) {
            return $date->copy()->endOfDay()->setTimezone('Asia/Tehran');
        }
        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance($date)->endOfDay()->setTimezone('Asia/Tehran');
        }
        if (is_string($date) && trim($date) !== '') {
            try {
                return Carbon::parse($date, 'Asia/Tehran')->endOfDay();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function event(
        string $id,
        string $kind,
        string $kindLabel,
        string $direction,
        float $amount,
        string $title,
        ?string $description,
        $at,
        ?string $href,
        ?float $signedAmount = null
    ): array {
        $carbon = null;
        if ($at instanceof Carbon) {
            $carbon = $at->copy()->setTimezone('Asia/Tehran');
        } elseif ($at instanceof \DateTimeInterface) {
            $carbon = Carbon::instance($at)->setTimezone('Asia/Tehran');
        } elseif (is_string($at) && $at !== '') {
            try {
                $carbon = Carbon::parse($at, 'Asia/Tehran');
            } catch (\Throwable $e) {
                $carbon = null;
            }
        }

        $signed = $signedAmount ?? ($direction === 'in' ? $amount : -$amount);

        return [
            'id' => $id,
            'kind' => $kind,
            'kind_label' => $kindLabel,
            'direction' => $direction,
            'amount' => round(abs($amount), 2),
            'signed_amount' => round($signed, 2),
            'title' => $title,
            'description' => $description,
            'at' => $carbon ? $carbon->toDateTimeString() : null,
            'at_jalali' => $carbon ? Jalalian::fromCarbon($carbon)->format('Y/m/d H:i') : null,
            'date' => $carbon ? $carbon->toDateString() : null,
            'date_jalali' => $carbon ? Jalalian::fromCarbon($carbon)->format('Y/m/d') : null,
            'href' => $href,
            'balance_after' => null,
        ];
    }
}
