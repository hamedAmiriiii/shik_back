<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\Cheque;
use App\Models\ManualTrade;
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
                        'account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_CHEQUE_RECEIVABLE),
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'چک دریافتنی',
                    ],
                    [
                        'account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_OTHER_INCOME),
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
                        'account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_CHEQUE_PAYABLE),
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
        $otherId = AccountingLedger::accountId(
            $atelierId,
            $isPurchase ? ChartOfAccountsSeeder::CODE_EXPENSE : ChartOfAccountsSeeder::CODE_OTHER_INCOME
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
