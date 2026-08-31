<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\Production;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AccountingProductionPoster
{
    public static function post(Production $production): ?AccountingVoucher
    {
        $atelierId = (int) $production->atelier_id;
        $amount = round((float) $production->total_cost, 2);
        if ($atelierId <= 0 || (int) $production->id <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        try {
            return AccountingVoucherService::post(
                $atelierId,
                AccountingLedger::eventDate($production->created_at),
                'تولید #'.$production->id,
                AccountingVoucher::SOURCE_PRODUCTION,
                (int) $production->id,
                [
                    [
                        'account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_INV_FINISHED),
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'کالای ساخته‌شده',
                    ],
                    [
                        'account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_INV_RAW),
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'مصرف مواد',
                    ],
                ]
            );
        } catch (RuntimeException $e) {
            Log::error('سند تولید ثبت نشد', [
                'production_id' => $production->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function reverse(Production $production): void
    {
        $atelierId = (int) $production->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_PRODUCTION,
            (int) $production->id
        );
    }
}
