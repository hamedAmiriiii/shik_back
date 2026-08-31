<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\Purchase;
use App\Models\PurchaseItemReturn;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AccountingReturnPoster
{
    public static function post(PurchaseItemReturn $log): ?AccountingVoucher
    {
        $atelierId = (int) $log->atelier_id;
        $sale = round((float) $log->return_sale_total, 2);
        $cost = round((float) $log->return_purchase_total, 2);
        if ($atelierId <= 0 || (int) $log->id <= 0 || ! AccountingLedger::ready()) {
            return null;
        }
        if ($sale < 0.01 && $cost < 0.01) {
            return null;
        }

        $purchasePosted = AccountingVoucherService::findPosted(
            $atelierId,
            AccountingVoucher::SOURCE_PURCHASE,
            (int) $log->purchase_id
        );
        if (! $purchasePosted) {
            return null;
        }

        $invCode = ChartOfAccountsSeeder::CODE_INV_CATALOG;
        if ($log->produced_good_id) {
            $invCode = ChartOfAccountsSeeder::CODE_INV_FINISHED;
        } elseif ($log->raw_material_id) {
            $invCode = ChartOfAccountsSeeder::CODE_INV_RAW;
        }

        try {
            $lines = [];
            AccountingLedger::push(
                $lines,
                AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_DISCOUNT),
                $sale,
                0,
                'برگشت از فروش'
            );
            AccountingLedger::push(
                $lines,
                AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_LOYALTY),
                0,
                $sale,
                'برگشت به اعتبار مشتری'
            );
            AccountingLedger::push(
                $lines,
                AccountingLedger::accountId($atelierId, $invCode),
                $cost,
                0,
                'بازگشت موجودی'
            );
            AccountingLedger::push(
                $lines,
                AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_COGS),
                0,
                $cost,
                'برگشت بها'
            );

            if (count($lines) < 2) {
                return null;
            }

            return AccountingVoucherService::post(
                $atelierId,
                AccountingLedger::eventDate($log->getRawOriginal('created_at')),
                'برگشت فروش #'.$log->purchase_id,
                AccountingVoucher::SOURCE_PURCHASE_RETURN,
                (int) $log->id,
                $lines
            );
        } catch (RuntimeException $e) {
            Log::error('سند برگشت ثبت نشد', [
                'return_id' => $log->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function reverseForPurchase(Purchase $purchase): void
    {
        $atelierId = (int) $purchase->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready() || ! Schema::hasTable('purchase_item_returns')) {
            return;
        }

        $ids = PurchaseItemReturn::query()
            ->where('purchase_id', $purchase->id)
            ->pluck('id');
        foreach ($ids as $id) {
            AccountingVoucherService::reversePostedIfAny(
                $atelierId,
                AccountingVoucher::SOURCE_PURCHASE_RETURN,
                (int) $id
            );
        }
    }
}
