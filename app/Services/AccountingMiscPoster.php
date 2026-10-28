<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\Cheque;
use App\Models\ManualTrade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AccountingMiscPoster
{
    public static function postReceivedCheque(Cheque $cheque): ?AccountingVoucher
    {
        $atelierId = (int) $cheque->atelier_id;
        $amount = round((float) $cheque->amount, 2);
        if (
            $atelierId <= 0
            || $amount < 0.01
            || $cheque->type !== Cheque::TYPE_RECEIVED
            || $cheque->purchase_id
            || ! AccountingLedger::ready()
        ) {
            return null;
        }

        try {
            return AccountingVoucherService::post(
                $atelierId,
                AccountingLedger::eventDate($cheque->getRawOriginal('issue_date') ?: $cheque->getRawOriginal('created_at')),
                'ثبت چک دریافتنی #'.$cheque->id,
                AccountingVoucher::SOURCE_INCOME,
                (int) $cheque->id,
                [
                    [
                        'account_id' => AccountingLedger::mapped($atelierId, 'income.cheque_receivable'),
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'چک دریافتنی',
                    ],
                    [
                        'account_id' => AccountingLedger::mapped($atelierId, 'income.other_income'),
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'درآمد متفرقه',
                    ],
                ]
            );
        } catch (RuntimeException $e) {
            Log::error('سند چک دریافتنی ثبت نشد', [
                'cheque_id' => $cheque->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function reverseReceivedCheque(Cheque $cheque): void
    {
        $atelierId = (int) $cheque->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_CHEQUE_CLEAR,
            (int) $cheque->id
        );
        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_INCOME,
            (int) $cheque->id
        );
    }

    public static function postIssuedChequeClear(Cheque $cheque): ?AccountingVoucher
    {
        $atelierId = (int) $cheque->atelier_id;
        $amount = round((float) $cheque->amount, 2);
        $shopAccountId = (int) ($cheque->shop_account_id ?? 0);
        if ($shopAccountId <= 0 && $cheque->invoice) {
            $shopAccountId = (int) ($cheque->invoice->shop_account_id ?? 0);
        }
        if ($shopAccountId <= 0 && $cheque->expense) {
            $shopAccountId = (int) ($cheque->expense->shop_account_id ?? 0);
        }
        if (
            $atelierId <= 0
            || $amount < 0.01
            || $cheque->type !== Cheque::TYPE_ISSUED
            || $shopAccountId <= 0
            || ! AccountingLedger::ready()
        ) {
            return null;
        }

        try {
            return AccountingVoucherService::post(
                $atelierId,
                AccountingLedger::eventDate($cheque->cleared_at),
                'وصول چک صادره #'.$cheque->id,
                AccountingVoucher::SOURCE_CHEQUE_CLEAR,
                (int) $cheque->id,
                [
                    [
                        'account_id' => AccountingLedger::mapped($atelierId, 'cheque_clear_issued.payable'),
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'بستن چک پرداختنی',
                    ],
                    [
                        'account_id' => AccountingLedger::shopCashAccountId($atelierId, $shopAccountId),
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'خروج از حساب',
                    ],
                ]
            );
        } catch (RuntimeException $e) {
            Log::error('سند وصول چک صادره ثبت نشد', [
                'cheque_id' => $cheque->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function reverseIssuedChequeClear(Cheque $cheque): void
    {
        $atelierId = (int) $cheque->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_CHEQUE_CLEAR,
            (int) $cheque->id
        );
    }

    /**
     * ظهرنویسی چک دریافتی بابت بدهی (+ اختیاری پیش‌پرداخت مابقی) — بدون حرکت نقد.
     *
     * @param  float  $settledAmount  مبلغ بسته‌شده از بدهی
     * @param  float  $advanceAmount  مابقی چک → پیش‌پرداخت ذینفع
     */
    public static function postChequeTransfer(
        Cheque $cheque,
        string $documentLabel,
        float $settledAmount,
        float $advanceAmount = 0.0
    ): ?AccountingVoucher {
        $atelierId = (int) $cheque->atelier_id;
        $chequeAmount = round((float) $cheque->amount, 2);
        $settledAmount = round($settledAmount, 2);
        $advanceAmount = round($advanceAmount, 2);
        if (
            $atelierId <= 0
            || $chequeAmount < 0.01
            || $cheque->type !== Cheque::TYPE_RECEIVED
            || $cheque->status !== Cheque::STATUS_TRANSFERRED
            || ! AccountingLedger::ready()
        ) {
            return null;
        }
        if (abs(($settledAmount + $advanceAmount) - $chequeAmount) > 0.02) {
            $settledAmount = min($settledAmount, $chequeAmount);
            $advanceAmount = round($chequeAmount - $settledAmount, 2);
        }

        $lines = [];
        if ($settledAmount >= 0.01) {
            $lines[] = [
                'account_id' => AccountingLedger::mapped($atelierId, 'cheque_transfer.ap'),
                'debit' => $settledAmount,
                'credit' => 0,
                'description' => 'بستن بدهی با ظهرنویسی چک',
            ];
        }
        if ($advanceAmount >= 0.01) {
            $lines[] = [
                'account_id' => AccountingLedger::mapped($atelierId, 'cheque_transfer.supplier_advance'),
                'debit' => $advanceAmount,
                'credit' => 0,
                'description' => 'پیش‌پرداخت ذینفع از مابقی چک',
            ];
        }
        $lines[] = [
            'account_id' => AccountingLedger::mapped($atelierId, 'cheque_transfer.cheque_receivable'),
            'debit' => 0,
            'credit' => $chequeAmount,
            'description' => 'خروج چک دریافتنی (ظهرنویسی)',
        ];

        try {
            return AccountingVoucherService::post(
                $atelierId,
                AccountingLedger::eventDate($cheque->transferred_at ?: now()),
                'ظهرنویسی چک #'.$cheque->id.' — '.$documentLabel,
                AccountingVoucher::SOURCE_CHEQUE_TRANSFER,
                (int) $cheque->id,
                $lines
            );
        } catch (RuntimeException $e) {
            Log::error('سند ظهرنویسی چک ثبت نشد', [
                'cheque_id' => $cheque->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function reverseChequeTransfer(Cheque $cheque): void
    {
        $atelierId = (int) $cheque->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_CHEQUE_TRANSFER,
            (int) $cheque->id
        );
    }

    /**
     * مصرف پیش‌پرداخت ذینفع روی بدهی سند: بدهکار پرداختنی / بستانکار پیش‌پرداخت.
     */
    public static function postBeneficiaryAdvanceApply(
        \App\Models\BeneficiaryAdvanceApplication $application,
        Model $document
    ): ?AccountingVoucher {
        $atelierId = (int) $application->atelier_id;
        $amount = round((float) $application->amount, 2);
        if ($atelierId <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        $label = $document instanceof \App\Models\Invoice
            ? 'فاکتور خرید #'.$document->id
            : 'هزینه #'.$document->id;

        try {
            return AccountingVoucherService::post(
                $atelierId,
                AccountingLedger::eventDate(now()),
                'مصرف اعتبار ظهرنویسی — '.$label,
                AccountingVoucher::SOURCE_BENEFICIARY_ADVANCE,
                (int) $application->id,
                [
                    [
                        'account_id' => AccountingLedger::mapped($atelierId, 'beneficiary_advance.ap'),
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'بستن بدهی با اعتبار ظهرنویسی',
                    ],
                    [
                        'account_id' => AccountingLedger::mapped($atelierId, 'beneficiary_advance.supplier_advance'),
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'کاهش پیش‌پرداخت ذینفع',
                    ],
                ]
            );
        } catch (RuntimeException $e) {
            Log::error('سند مصرف اعتبار ظهرنویسی ثبت نشد', [
                'application_id' => $application->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function postManualTrade(ManualTrade $trade): ?AccountingVoucher
    {
        $atelierId = (int) $trade->atelier_id;
        $amount = round((float) $trade->amount, 2);
        $shopAccountId = (int) ($trade->shop_account_id ?? 0);
        if ($atelierId <= 0 || $amount < 0.01 || $shopAccountId <= 0 || ! AccountingLedger::ready()) {
            return null;
        }

        $cashId = AccountingLedger::shopCashAccountId($atelierId, $shopAccountId);
        $isPurchase = $trade->type === ManualTrade::TYPE_PURCHASE;
        $otherId = AccountingLedger::mapped(
            $atelierId,
            $isPurchase ? 'manual_trade.purchase_expense' : 'manual_trade.sale_income'
        );

        try {
            return AccountingVoucherService::post(
                $atelierId,
                AccountingLedger::eventDate($trade->getRawOriginal('date')),
                ($trade->title ?: 'سند دستی').' #'.$trade->id,
                AccountingVoucher::SOURCE_MANUAL_TRADE,
                (int) $trade->id,
                $isPurchase
                    ? [
                        ['account_id' => $otherId, 'debit' => $amount, 'credit' => 0, 'description' => 'خرید دستی'],
                        ['account_id' => $cashId, 'debit' => 0, 'credit' => $amount, 'description' => 'پرداخت از حساب'],
                    ]
                    : [
                        ['account_id' => $cashId, 'debit' => $amount, 'credit' => 0, 'description' => 'ورود به حساب'],
                        ['account_id' => $otherId, 'debit' => 0, 'credit' => $amount, 'description' => 'فروش دستی'],
                    ]
            );
        } catch (RuntimeException $e) {
            Log::error('سند خرید/فروش دستی ثبت نشد', [
                'manual_trade_id' => $trade->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function reverseManualTrade(ManualTrade $trade): void
    {
        $atelierId = (int) $trade->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_MANUAL_TRADE,
            (int) $trade->id
        );
    }
}
