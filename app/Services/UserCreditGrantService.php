<?php

namespace App\Services;

use App\Models\UserCreditGrant;

class UserCreditGrantService
{
    public static function recordManualChange(
        int $atelierId,
        string $phone,
        string $creditType,
        float $oldAmount,
        float $newAmount
    ): void {
        $delta = round($newAmount - $oldAmount, 2);
        if (abs($delta) < 0.01 || ! self::tableExists()) {
            return;
        }

        UserCreditGrant::create([
            'atelier_id' => $atelierId,
            'phone' => $phone,
            'credit_type' => $creditType,
            'amount' => $delta,
            'source' => UserCreditGrant::SOURCE_MANUAL,
        ]);
    }

    /**
     * مجموع اعتبار دستی صادرشده در بازه (مثبت = هدیه، منفی = برگشت/کاهش).
     */
    public static function sumManualGrantsInRange(int $atelierId, string $start, string $end): float
    {
        if (! self::tableExists()) {
            return 0.0;
        }

        return (float) UserCreditGrant::query()
            ->where('atelier_id', $atelierId)
            ->where('source', UserCreditGrant::SOURCE_MANUAL)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
    }

    protected static function tableExists(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('user_credit_grants');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
