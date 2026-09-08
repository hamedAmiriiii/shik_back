<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\ShopDailyTicketCounter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class DailyTicketNumberService
{
    /**
     * شماره فیش روزانه فروشگاه را روی فاکتور می‌گذارد (از ۱؛ هر روز تهران از نو).
     * باید داخل تراکنش فروش صدا زده شود تا دو صندوق همزمان شماره تکراری نگیرند.
     */
    public static function assign(Purchase $purchase): ?int
    {
        if (! Schema::hasColumn('purchases', 'daily_ticket_number')) {
            return null;
        }

        if ($purchase->daily_ticket_number) {
            return (int) $purchase->daily_ticket_number;
        }

        $atelierId = (int) $purchase->atelier_id;
        if ($atelierId <= 0) {
            return null;
        }

        $date = now('Asia/Tehran')->toDateString();
        $next = self::nextNumber($atelierId, $date);
        if ($next === null) {
            return null;
        }

        $purchase->daily_ticket_number = $next;
        if (Schema::hasColumn('purchases', 'daily_ticket_date')) {
            $purchase->daily_ticket_date = $date;
        }
        $purchase->save();

        return $next;
    }

    protected static function nextNumber(int $atelierId, string $date): ?int
    {
        if (! Schema::hasTable('shop_daily_ticket_counters')) {
            $max = Purchase::query()
                ->where('atelier_id', $atelierId)
                ->whereDate('daily_ticket_date', $date)
                ->lockForUpdate()
                ->max('daily_ticket_number');

            return ((int) $max) + 1;
        }

        $counter = ShopDailyTicketCounter::query()
            ->where('atelier_id', $atelierId)
            ->whereDate('ticket_date', $date)
            ->lockForUpdate()
            ->first();

        if (! $counter) {
            try {
                ShopDailyTicketCounter::query()->create([
                    'atelier_id' => $atelierId,
                    'ticket_date' => $date,
                    'last_number' => 0,
                ]);
            } catch (QueryException $e) {
                // ردیف را صندوق دیگر ساخته
            }
            $counter = ShopDailyTicketCounter::query()
                ->where('atelier_id', $atelierId)
                ->whereDate('ticket_date', $date)
                ->lockForUpdate()
                ->first();
        }

        if (! $counter) {
            return 1;
        }

        $next = ((int) $counter->last_number) + 1;
        $counter->last_number = $next;
        $counter->save();

        return $next;
    }
}
