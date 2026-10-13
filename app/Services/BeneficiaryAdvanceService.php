<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\BeneficiaryAdvance;
use App\Models\BeneficiaryAdvanceApplication;
use App\Models\Cheque;
use App\Models\DocumentPayment;
use App\Models\Expense;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BeneficiaryAdvanceService
{
    public static function supports(): bool
    {
        return Schema::hasTable('beneficiary_advances')
            && Schema::hasTable('beneficiary_advance_applications');
    }

    public static function availableBalance(int $atelierId, int $beneficiaryId): float
    {
        if (! self::supports() || $atelierId <= 0 || $beneficiaryId <= 0) {
            return 0.0;
        }

        return round((float) BeneficiaryAdvance::query()
            ->where('atelier_id', $atelierId)
            ->where('beneficiary_id', $beneficiaryId)
            ->whereIn('status', [BeneficiaryAdvance::STATUS_OPEN, BeneficiaryAdvance::STATUS_PARTIAL])
            ->sum('remaining'), 2);
    }

    /**
     * ثبت پیش‌پرداخت از بیش‌پرداخت ظهرنویسی چک.
     */
    public static function createFromChequeTransfer(
        Cheque $cheque,
        Model $document,
        int $beneficiaryId,
        float $advanceAmount,
        ?string $note = null
    ): ?BeneficiaryAdvance {
        if (! self::supports()) {
            throw new RuntimeException('جدول پیش‌پرداخت ذینفع آماده نیست. SQL مربوط را اجرا کنید.');
        }
        $advanceAmount = round($advanceAmount, 2);
        if ($advanceAmount < 0.01) {
            return null;
        }
        if ($beneficiaryId <= 0) {
            throw new RuntimeException('برای ثبت مابقی چک به‌عنوان اعتبار، ذینفع سند الزامی است.');
        }
        if (! ShopBeneficiaryService::findForShop((int) $cheque->atelier_id, $beneficiaryId)) {
            throw new RuntimeException('ذینفع متعلق به این فروشگاه نیست.');
        }

        return BeneficiaryAdvance::create([
            'atelier_id' => (int) $cheque->atelier_id,
            'beneficiary_id' => $beneficiaryId,
            'amount' => $advanceAmount,
            'remaining' => $advanceAmount,
            'source' => BeneficiaryAdvance::SOURCE_CHEQUE_TRANSFER,
            'cheque_id' => $cheque->id,
            'settled_invoice_id' => $document instanceof Invoice ? $document->id : null,
            'settled_expense_id' => $document instanceof Expense ? $document->id : null,
            'status' => BeneficiaryAdvance::STATUS_OPEN,
            'note' => $note,
        ]);
    }

    /**
     * برگشت پیش‌پرداخت ظهرنویسی — فقط اگر هنوز مصرف نشده باشد.
     */
    public static function reverseForCheque(Cheque $cheque): void
    {
        if (! self::supports()) {
            return;
        }

        $advance = BeneficiaryAdvance::query()
            ->where('cheque_id', $cheque->id)
            ->where('status', '!=', BeneficiaryAdvance::STATUS_REVERSED)
            ->lockForUpdate()
            ->first();
        if (! $advance) {
            return;
        }

        $amount = round((float) $advance->amount, 2);
        $remaining = round((float) $advance->remaining, 2);
        if ($remaining + 0.001 < $amount) {
            throw new RuntimeException(
                'بخشی از اعتبار ظهرنویسی این چک قبلاً مصرف شده ('.number_format($amount - $remaining).' تومان) و برگشت ظهرنویسی ممکن نیست.'
            );
        }

        foreach ($advance->applications as $app) {
            AccountingVoucherService::reversePostedIfAny(
                (int) $advance->atelier_id,
                AccountingVoucher::SOURCE_BENEFICIARY_ADVANCE,
                (int) $app->id
            );
            $app->delete();
        }

        $advance->update([
            'remaining' => 0,
            'status' => BeneficiaryAdvance::STATUS_REVERSED,
        ]);
    }

    /**
     * مصرف اعتبار پیش‌پرداخت روی نسیهٔ فاکتور/هزینه.
     *
     * @return array{applied: float, applications: array<int, BeneficiaryAdvanceApplication>}
     */
    public static function applyToDocument(Model $model, ?float $amount = null): array
    {
        if (! self::supports()) {
            throw new RuntimeException('پیش‌پرداخت ذینفع در این نسخه فعال نیست.');
        }
        if (! $model instanceof Invoice && ! $model instanceof Expense) {
            throw new RuntimeException('این سند قابل تسویه با اعتبار ذینفع نیست.');
        }
        if (! Schema::hasColumn($model->getTable(), 'beneficiary_id') || ! $model->beneficiary_id) {
            throw new RuntimeException('برای مصرف اعتبار، ذینفع سند باید مشخص باشد.');
        }

        DocumentPaymentService::alignSplitsToDocumentAmount($model);

        $remainingCredit = DocumentPaymentService::remainingCredit($model);
        if ($remainingCredit <= 0.001) {
            throw new RuntimeException('بدهی نسیه برای این سند باقی نمانده است.');
        }

        $available = self::availableBalance((int) $model->atelier_id, (int) $model->beneficiary_id);
        if ($available <= 0.001) {
            throw new RuntimeException('اعتبار پیش‌پرداخت برای این ذینفع موجود نیست.');
        }

        $pay = $amount === null ? min($remainingCredit, $available) : round($amount, 2);
        if ($pay <= 0) {
            throw new RuntimeException('مبلغ مصرف اعتبار نامعتبر است.');
        }
        if ($pay - $remainingCredit > 0.001) {
            throw new RuntimeException('مبلغ بیشتر از مانده نسیه سند است.');
        }
        if ($pay - $available > 0.001) {
            throw new RuntimeException('مبلغ بیشتر از اعتبار موجود ذینفع ('.number_format($available).') است.');
        }

        return DB::transaction(function () use ($model, $pay) {
            $left = $pay;
            $applications = [];

            $advances = BeneficiaryAdvance::query()
                ->where('atelier_id', (int) $model->atelier_id)
                ->where('beneficiary_id', (int) $model->beneficiary_id)
                ->whereIn('status', [BeneficiaryAdvance::STATUS_OPEN, BeneficiaryAdvance::STATUS_PARTIAL])
                ->where('remaining', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($advances as $advance) {
                if ($left <= 0.001) {
                    break;
                }
                $slice = min(round((float) $advance->remaining, 2), $left);
                if ($slice < 0.01) {
                    continue;
                }

                // کاهش نسیه
                self::consumeCreditRows($model, $slice);

                $payment = DocumentPayment::create([
                    'atelier_id' => (int) $model->atelier_id,
                    'invoice_id' => $model instanceof Invoice ? $model->id : null,
                    'expense_id' => $model instanceof Expense ? $model->id : null,
                    'method' => DocumentPaymentService::METHOD_ADVANCE,
                    'amount' => $slice,
                    'shop_account_id' => null,
                    'cheque_id' => null,
                    'settled' => true,
                    'sort_order' => (int) $model->payments()->max('sort_order') + 1,
                ]);

                $app = BeneficiaryAdvanceApplication::create([
                    'atelier_id' => (int) $model->atelier_id,
                    'advance_id' => $advance->id,
                    'invoice_id' => $model instanceof Invoice ? $model->id : null,
                    'expense_id' => $model instanceof Expense ? $model->id : null,
                    'document_payment_id' => $payment->id,
                    'amount' => $slice,
                ]);

                $advance->remaining = round((float) $advance->remaining - $slice, 2);
                $advance->refreshStatus();

                $applications[] = $app;
                $left = round($left - $slice, 2);
            }

            if ($left > 0.001) {
                throw new RuntimeException('اعتبار کافی برای مصرف این مبلغ نبود.');
            }

            DocumentPaymentService::refreshDocumentSummary($model);
            $fresh = $model->fresh(['payments']);
            if ($fresh instanceof Invoice) {
                AccountingDocumentPoster::syncInvoice($fresh);
            } elseif ($fresh instanceof Expense) {
                AccountingDocumentPoster::syncExpense($fresh);
            }

            return [
                'applied' => $pay,
                'applications' => $applications,
            ];
        });
    }

    protected static function consumeCreditRows(Model $model, float $pay): void
    {
        $left = $pay;
        $creditQuery = $model->payments()->where('method', DocumentPaymentService::METHOD_CREDIT)->where('settled', false);
        foreach ($creditQuery->orderBy('sort_order')->orderBy('id')->get() as $row) {
            if ($left <= 0.001) {
                break;
            }
            $rowAmount = round((float) $row->amount, 2);
            if ($rowAmount - $left <= 0.001) {
                $row->update(['settled' => true]);
                $left = round($left - $rowAmount, 2);
            } else {
                $row->update(['amount' => round($rowAmount - $left, 2)]);
                $left = 0;
            }
        }
        if ($left > 0.001) {
            throw new RuntimeException('مانده نسیه برای مصرف اعتبار کافی نیست.');
        }
    }
}
