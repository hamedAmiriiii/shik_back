<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\InventoryWaste;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AccountingInventoryWastePoster
{
    public static function post(InventoryWaste $waste): ?AccountingVoucher
    {
        $atelierId = (int) $waste->atelier_id;
        $amount = round((float) $waste->total_cost, 2);
        if ($atelierId <= 0 || (int) $waste->id <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        $inventoryEvent = $waste->isProducedGood()
            ? 'inventory_waste.inv_finished'
            : 'inventory_waste.inv_raw';

        try {
            return AccountingVoucherService::post(
                $atelierId,
                AccountingLedger::eventDate($waste->wasted_at ?: $waste->created_at),
                'ضایعات #'.$waste->id,
                AccountingVoucher::SOURCE_INVENTORY_WASTE,
                (int) $waste->id,
                [
                    [
                        'account_id' => AccountingLedger::mapped($atelierId, 'inventory_waste.cogs'),
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'ضایعات انبار',
                    ],
                    [
                        'account_id' => AccountingLedger::mapped($atelierId, $inventoryEvent),
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => $waste->isProducedGood() ? 'کاهش کالای ساخته‌شده' : 'کاهش مواد اولیه',
                    ],
                ]
            );
        } catch (RuntimeException $e) {
            Log::error('سند ضایعات ثبت نشد', [
                'inventory_waste_id' => $waste->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function reverse(InventoryWaste $waste): void
    {
        $atelierId = (int) $waste->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_INVENTORY_WASTE,
            (int) $waste->id
        );
    }
}
