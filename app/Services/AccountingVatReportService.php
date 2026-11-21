<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * گزارش ارزش افزوده خرید (اعتبار) و فروش (خروجی) برای یک بازه.
 */
class AccountingVatReportService
{
    /**
     * @return array<string, mixed>
     */
    public static function report(int $atelierId, ?string $from = null, ?string $to = null): array
    {
        if ($atelierId <= 0) {
            throw new RuntimeException('فروشگاه نامعتبر است.');
        }

        $fromG = AccountingReportService::parseDate($from);
        $toG = AccountingReportService::parseDate($to);
        if ($fromG && $toG && $fromG > $toG) {
            throw new RuntimeException('بازهٔ تاریخ نامعتبر است.');
        }

        $rows = [];
        $invoiceTotal = 0.0;
        $expenseTotal = 0.0;

        if (PurchaseVatService::columnsReady('invoices')) {
            foreach (self::documentQuery(Invoice::query(), $atelierId, $fromG, $toG)->get() as $invoice) {
                $vat = round((float) ($invoice->vat_amount ?? 0), 2);
                if ($vat < 0.01) {
                    continue;
                }
                $invoiceTotal = round($invoiceTotal + $vat, 2);
                $rows[] = self::rowFromDocument($invoice, 'invoice', 'فاکتور خرید');
            }
        }

        if (PurchaseVatService::columnsReady('expenses')) {
            foreach (self::documentQuery(Expense::query(), $atelierId, $fromG, $toG)->get() as $expense) {
                $vat = round((float) ($expense->vat_amount ?? 0), 2);
                if ($vat < 0.01) {
                    continue;
                }
                $expenseTotal = round($expenseTotal + $vat, 2);
                $rows[] = self::rowFromDocument($expense, 'expense', 'هزینه');
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $dateCmp = strcmp((string) $b['date'], (string) $a['date']);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return ((int) $b['id']) <=> ((int) $a['id']);
        });

        $ledger = AccountingReportService::vatAccountSummary($atelierId, $fromG, $toG);
        $inputFromDocs = round($invoiceTotal + $expenseTotal, 2);
        $outputLedger = (float) ($ledger['output']['net'] ?? 0);
        $inputLedger = (float) ($ledger['input']['net'] ?? 0);

        return [
            'from' => self::toJalaliDisplay($fromG),
            'to' => self::toJalaliDisplay($toG),
            'enabled' => PurchaseVatService::enabledForAtelier($atelierId),
            'rows' => $rows,
            'totals' => [
                'input_invoices' => $invoiceTotal,
                'input_expenses' => $expenseTotal,
                'input_documents' => $inputFromDocs,
                'input_ledger' => $inputLedger,
                'output_ledger' => $outputLedger,
                // بدهی تقریبی دوره: خروجی − اعتبار خرید (منفی = اعتبار بیشتر)
                'net_payable' => round($outputLedger - $inputFromDocs, 2),
            ],
            'accounts' => $ledger,
            'note' => 'ارزش افزوده خرید از فاکتور و هزینه می‌آید؛ طرف معامله همان ذینفع ثبت‌شده روی سند است. خروجی فروش فعلاً از حساب ۲۱۳۰۱ خوانده می‌شود (در فروشگاه‌های فعلی معمولاً صفر است).',
        ];
    }

    /**
     * @param  Builder<Invoice>|Builder<Expense>  $query
     * @return Builder<Invoice>|Builder<Expense>
     */
    protected static function documentQuery(Builder $query, int $atelierId, ?string $fromG, ?string $toG): Builder
    {
        $query->where('atelier_id', $atelierId)
            ->where('vat_amount', '>', 0)
            ->with(['beneficiary:id,name,phone']);

        if ($fromG) {
            $query->whereDate('date', '>=', $fromG);
        }
        if ($toG) {
            $query->whereDate('date', '<=', $toG);
        }

        return $query->orderByDesc('date')->orderByDesc('id');
    }

    /**
     * @param  Invoice|Expense  $document
     * @return array<string, mixed>
     */
    protected static function rowFromDocument($document, string $kind, string $kindLabel): array
    {
        $beneficiary = $document->beneficiary;
        $partyName = trim((string) ($beneficiary->name ?? ''));
        $partyPhone = trim((string) ($beneficiary->phone ?? ''));
        if ($partyName === '' && $partyPhone === '') {
            $partyName = trim((string) ($document->user_name ?? ''));
        }

        $base = round((float) ($document->amount ?? 0), 2);
        $vat = round((float) ($document->vat_amount ?? 0), 2);
        $discount = $document instanceof Invoice && Schema::hasColumn('invoices', 'discount_amount')
            ? round((float) ($document->discount_amount ?? 0), 2)
            : 0.0;

        $number = null;
        if ($document instanceof Invoice && Schema::hasColumn('invoices', 'number')) {
            $number = $document->number !== null ? (int) $document->number : null;
        }

        return [
            'id' => (int) $document->id,
            'kind' => $kind,
            'kind_label' => $kindLabel,
            'number' => $number,
            'date' => (string) $document->date,
            'title' => (string) ($document->title ?? ''),
            'party_name' => $partyName !== '' ? $partyName : null,
            'party_phone' => $partyPhone !== '' ? $partyPhone : null,
            'base_amount' => $base,
            'discount_amount' => $discount,
            'vat_mode' => (string) ($document->vat_mode ?? 'none'),
            'vat_percent' => $document->vat_percent !== null ? round((float) $document->vat_percent, 2) : null,
            'vat_amount' => $vat,
            'payable_amount' => PurchaseVatService::payableAmount($document),
            'payment_status' => (string) ($document->payment_status ?? ''),
            'payment_status_label' => (string) ($document->payment_status_label ?? ''),
            'href' => $kind === 'invoice'
                ? '/admin/invoices'
                : '/admin/expenses',
        ];
    }

    protected static function toJalaliDisplay(?string $gregorian): ?string
    {
        if (! $gregorian) {
            return null;
        }

        return \Morilog\Jalali\Jalalian::fromCarbon(
            \Carbon\Carbon::parse($gregorian)
        )->format('Y-m-d');
    }
}
