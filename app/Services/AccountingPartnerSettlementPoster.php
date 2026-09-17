<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\ShopPartnerSettlement;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AccountingPartnerSettlementPoster
{
    public static function post(ShopPartnerSettlement $settlement): ?AccountingVoucher
    {
        $atelierId = (int) $settlement->atelier_id;
        $amount = round((float) $settlement->total_distributed, 2);
        $shopAccountId = (int) ($settlement->shop_account_id ?? 0);
        if ($atelierId <= 0 || $amount < 0.01 || $shopAccountId <= 0 || ! AccountingLedger::ready()) {
            return null;
        }

        try {
            $lines = [];
            AccountingLedger::push(
                $lines,
                AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_EQUITY),
                $amount,
                0,
                'تقسیم سود شرکا'
            );
            AccountingLedger::push(
                $lines,
                AccountingLedger::shopCashAccountId($atelierId, $shopAccountId),
                0,
                $amount,
                'برداشت تقسیم سود از حساب'
            );

            return AccountingVoucherService::post(
                $atelierId,
                $settlement->settled_at,
                'تقسیم سود شرکا #'.$settlement->id,
                AccountingVoucher::SOURCE_PARTNER_SETTLEMENT,
                (int) $settlement->id,
                $lines
            );
        } catch (RuntimeException $e) {
            Log::error('سند تقسیم سود شرکا ثبت نشد', [
                'settlement_id' => $settlement->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function reverse(ShopPartnerSettlement $settlement): void
    {
        $atelierId = (int) $settlement->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready()) {
            return;
        }
        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_PARTNER_SETTLEMENT,
            (int) $settlement->id
        );
    }
}
