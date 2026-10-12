<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\UserShiksho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * گردش مالی کامل یک شخص (مشتری / ذینفع) در یک فروشگاه:
 *  - فروش به او: اگر مانده داشته باشد «بدهکار» است (او به فروشگاه بدهکار است)
 *  - فاکتور و هزینهٔ ثبت‌شده به نام او: اگر پرداخت‌نشده باشد «بستانکار» است (فروشگاه به او بدهکار است)
 */
class CustomerLedgerService
{
    public const SIDE_DEBTOR = 'debtor';

    public const SIDE_CREDITOR = 'creditor';

    public const SIDE_SETTLED = 'settled';

    /**
     * @param  Collection<int, Purchase>  $purchases  فروش‌های این شخص (با purchasedProducts.product)
     * @return array{entries: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function build(int $atelierId, Collection $purchases, ?UserShiksho $person): array
    {
        $entries = [];

        foreach ($purchases as $purchase) {
            $remaining = self::saleRemaining($purchase);
            $entries[] = [
                'key' => 'sale-'.$purchase->id,
                'type' => 'sale',
                'id' => (int) $purchase->id,
                'date' => $purchase->created_at,
                'sort_at' => (string) $purchase->getRawOriginal('created_at'),
                'description' => self::saleItems($purchase),
                'payment_type' => $purchase->payment_type,
                'amount' => round((float) $purchase->total_amount, 2),
                'debtor_amount' => $remaining,
                'creditor_amount' => 0.0,
                'side' => $remaining > 0.009 ? self::SIDE_DEBTOR : self::SIDE_SETTLED,
            ];
        }

        if ($person) {
            foreach (['invoice' => Invoice::class, 'expense' => Expense::class] as $type => $modelClass) {
                $table = $type === 'invoice' ? 'invoices' : 'expenses';
                if (! ShopBeneficiaryService::supports($table)) {
                    continue;
                }
                $rows = $modelClass::query()
                    ->where('atelier_id', $atelierId)
                    ->where('beneficiary_id', $person->id)
                    ->get();
                foreach ($rows as $doc) {
                    $remaining = self::documentRemaining($doc);
                    $entries[] = [
                        'key' => $type.'-'.$doc->id,
                        'type' => $type,
                        'id' => (int) $doc->id,
                        'date' => $doc->date,
                        'sort_at' => (string) ($doc->getRawOriginal('created_at') ?: $doc->getRawOriginal('date')),
                        'description' => (string) ($doc->title ?? ''),
                        'payment_type' => null,
                        'amount' => round((float) $doc->amount, 2),
                        'debtor_amount' => 0.0,
                        'creditor_amount' => $remaining,
                        'side' => $remaining > 0.009 ? self::SIDE_CREDITOR : self::SIDE_SETTLED,
                    ];
                }
            }
        }

        usort($entries, function (array $a, array $b) {
            $cmp = strcmp($b['sort_at'], $a['sort_at']);

            return $cmp !== 0 ? $cmp : $b['id'] <=> $a['id'];
        });

        $salesTotal = 0.0;
        $docsTotal = 0.0;
        $debtor = 0.0;
        $creditor = 0.0;
        foreach ($entries as $entry) {
            if ($entry['type'] === 'sale') {
                $salesTotal += $entry['amount'];
            } else {
                $docsTotal += $entry['amount'];
            }
            $debtor += $entry['debtor_amount'];
            $creditor += $entry['creditor_amount'];
        }
        $net = round($debtor - $creditor, 2);

        return [
            'entries' => $entries,
            'summary' => [
                'sales_total' => round($salesTotal, 2),
                'documents_total' => round($docsTotal, 2),
                'debtor_total' => round($debtor, 2),
                'creditor_total' => round($creditor, 2),
                'net' => $net,
                'net_side' => $net > 0.009 ? self::SIDE_DEBTOR : ($net < -0.009 ? self::SIDE_CREDITOR : self::SIDE_SETTLED),
            ],
        ];
    }

    /** مانده‌ای که مشتری هنوز بابت این فروش نپرداخته */
    protected static function saleRemaining(Purchase $purchase): float
    {
        try {
            if ($purchase->isDebt()) {
                return round($purchase->outstandingDebtAmount(), 2);
            }
            if ($purchase->isInstallment()) {
                return max(0.0, round((float) $purchase->total_amount - (float) $purchase->paid_amount, 2));
            }
            if ($purchase->isCheque()) {
                return round($purchase->outstandingChequeAmount(), 2);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return 0.0;
    }

    /** مانده‌ای که فروشگاه هنوز بابت این فاکتور/هزینه نپرداخته */
    protected static function documentRemaining(Model $doc): float
    {
        if (! Schema::hasColumn($doc->getTable(), 'payment_status')) {
            return 0.0;
        }
        if (! in_array($doc->payment_status, [DocumentPaymentService::STATUS_UNPAID, DocumentPaymentService::STATUS_PARTIAL], true)) {
            return 0.0;
        }

        $remaining = DocumentPaymentService::remainingCredit($doc);
        if ($remaining > 0.009) {
            return $remaining;
        }
        if (DocumentPaymentService::supportsSplits() && $doc->payments()->exists()) {
            return 0.0;
        }

        return round((float) $doc->amount, 2);
    }

    protected static function saleItems(Purchase $purchase): string
    {
        $labels = [];
        foreach ($purchase->purchasedProducts as $item) {
            $name = trim((string) ($item->display_name ?? $item->item_name ?? ($item->product->name ?? '')));
            if ($name === '') {
                continue;
            }
            $qty = (float) ($item->quantity ?? 1);
            $labels[] = $qty > 1 ? $name.' × '.rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') : $name;
        }

        return implode('، ', $labels);
    }
}
