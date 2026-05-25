<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Services\ShopDashboardService;
use App\Services\ShopSalesReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;

class DashboardController extends Controller
{
    /**
     * خلاصهٔ صفحهٔ اصلی: تعداد کالا، فروش امروز، کارت، نقد دستی.
     */
    public function summary(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $date = null;
        if ($request->filled('date')) {
            $request->validate(['date' => 'date']);
            $date = Carbon::parse($request->input('date'))->setTimezone('Asia/Tehran');
        }

        $data = ShopDashboardService::summary($atelierId, $date);

        return response(array_merge($data, [
            'meta' => ['atelier_id' => $atelierId],
        ]), 200);
    }

    /**
     * فروش روزانه — پیش‌فرض ۱۰ روز اخیر.
     */
    public function salesByDay(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $days = (int) $request->input('days', 10);
        $request->validate(['days' => 'sometimes|integer|min:1|max:62']);
        if ($request->has('days')) {
            $days = (int) $request->input('days');
        }

        $data = ShopDashboardService::salesByDay($atelierId, $days);

        return response(array_merge($data, [
            'meta' => ['atelier_id' => $atelierId],
        ]), 200);
    }

    /**
     * فروش یک روز: نقد، کارت، اقساط وصول‌شده، اعتبار مصرف‌شده، جمع وصول.
     * GET /api/dashboard/daily-sales
     * GET /api/dashboard/daily-sales?date=2026-05-22
     */
    public function dailySales(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $request->validate(['date' => 'sometimes|date']);
        $dateTehran = $request->filled('date')
            ? Carbon::parse($request->input('date'))->setTimezone('Asia/Tehran')->startOfDay()
            : Carbon::now('Asia/Tehran')->startOfDay();

        $dateKey = $dateTehran->format('Y-m-d');
        $metrics = ShopSalesReportService::salesAndProfitForDate($atelierId, $dateTehran);

        $rangeStart = $dateTehran->copy()->startOfDay()->format('Y-m-d H:i:s');
        $rangeEnd = $dateTehran->copy()->endOfDay()->format('Y-m-d H:i:s');
        $purchasesCount = Purchase::query()
            ->forAtelier($atelierId)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->count();

        return response([
            'date' => $dateKey,
            'date_jalali' => Jalalian::fromCarbon($dateTehran)->format('Y-m-d'),
            'purchases_count' => $purchasesCount,
            'gross_sales' => (float) $metrics['gross_sales'],
            'total_sales' => (float) $metrics['sales'],
            'total_returns' => (float) $metrics['returns'],
            'cash_amount' => (float) $metrics['cash_amount'],
            'card_amount' => (float) $metrics['card_amount'],
            'cash_and_card_total' => (float) $metrics['cash_and_card_total'],
            'installments_collected' => (float) $metrics['installments_collected'],
            'total_collected' => (float) $metrics['total_collected'],
            'credit_used_total' => (float) $metrics['credit_used_total'],
            'settlement_total' => (float) $metrics['settlement_total'],
            'uncollected_installments' => (float) $metrics['uncollected_installments'],
            'meta' => ['atelier_id' => $atelierId],
        ], 200);
    }
}
