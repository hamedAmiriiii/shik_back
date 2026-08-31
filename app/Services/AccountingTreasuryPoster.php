<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\DailyShopReconciliationAccountDeposit;
use App\Models\ShopAccountTransfer;
use Carbon\Carbon;

class AccountingTreasuryPoster
{
    public static function syncReconDeposit(
        DailyShopReconciliationAccountDeposit $deposit,
        int $atelierId,
        $date = null
    ): ?AccountingVoucher {
        $sourceId = (int) $deposit->id;
        $amount = round((float) $deposit->amount, 2);
        if ($atelierId <= 0 || $sourceId <= 0 || ! AccountingLedger::ready()) {
            return null;
        }

        if ($amount < 0.01) {
            AccountingVoucherService::reversePostedIfAny(
                $atelierId,
                AccountingVoucher::SOURCE_RECON_DEPOSIT,
                $sourceId
            );

            return null;
        }

        $existing = AccountingVoucherService::findPosted(
            $atelierId,
            AccountingVoucher::SOURCE_RECON_DEPOSIT,
            $sourceId
        );
        if ($existing) {
            $posted = 0.0;
            foreach ($existing->lines as $line) {
                $posted += (float) $line->debit;
            }
            if (abs(round($posted, 2) - $amount) < 0.01) {
                return $existing;
            }
            AccountingVoucherService::reverse($existing);
        }

        $shopAccountId = (int) $deposit->shop_account_id;
        $voucherDate = $date;
        if (! $voucherDate) {
            $deposit->loadMissing('reconciliation');
            $voucherDate = $deposit->reconciliation
                ? $deposit->reconciliation->getRawOriginal('date')
                : Carbon::now('Asia/Tehran')->toDateString();
        }

        return AccountingVoucherService::post(
            $atelierId,
            $voucherDate,
            'واریز تطبیق روزانه به حساب '.$shopAccountId,
            AccountingVoucher::SOURCE_RECON_DEPOSIT,
            $sourceId,
            [
                [
                    'account_id' => AccountingLedger::shopCashAccountId($atelierId, $shopAccountId),
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'واریز به حساب فروشگاه',
                ],
                [
                    'account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_TILL),
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'خروج از صندوق فروش',
                ],
            ]
        );
    }

    public static function voidReconDeposit(int $atelierId, int $depositId): void
    {
        if ($atelierId <= 0 || $depositId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_RECON_DEPOSIT,
            $depositId
        );
    }

    public static function postTransfer(ShopAccountTransfer $transfer): ?AccountingVoucher
    {
        $atelierId = (int) $transfer->atelier_id;
        $amount = round((float) $transfer->amount, 2);
        if ($atelierId <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        $date = $transfer->getRawOriginal('date') ?: $transfer->date;

        return AccountingVoucherService::post(
            $atelierId,
            $date,
            $transfer->title ?: 'شارژ تنخواه',
            AccountingVoucher::SOURCE_ACCOUNT_TRANSFER,
            (int) $transfer->id,
            [
                [
                    'account_id' => AccountingLedger::shopCashAccountId($atelierId, (int) $transfer->to_shop_account_id),
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'ورود به تنخواه',
                ],
                [
                    'account_id' => AccountingLedger::shopCashAccountId($atelierId, (int) $transfer->from_shop_account_id),
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'خروج از حساب مبدأ',
                ],
            ]
        );
    }

    public static function reverseTransfer(ShopAccountTransfer $transfer): void
    {
        $atelierId = (int) $transfer->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_ACCOUNT_TRANSFER,
            (int) $transfer->id
        );
    }
}
