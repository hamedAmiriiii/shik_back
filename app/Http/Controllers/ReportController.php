<?php

namespace App\Http\Controllers;

use App\Models\PurchasedProduct;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

class ReportController extends Controller
{
    public function index()
    {
        $reports = [];

        // 1. مجموع فروش و سود روزانه
        $today = Carbon::today();
        $todayData = $this->getSalesAndProfit($today, $today->copy()->endOfDay());
        $reports['today'] = [
            'total_sales' => $todayData['sales'],
            'total_profit' => $todayData['profit']
        ];

        // 2. مجموع فروش و سود روز قبل
        $yesterday = Carbon::yesterday();
        $yesterdayData = $this->getSalesAndProfit($yesterday->copy()->startOfDay(), $yesterday->copy()->endOfDay());
        $reports['yesterday'] = [
            'total_sales' => $yesterdayData['sales'],
            'total_profit' => $yesterdayData['profit']
        ];

        // 3. مجموع فروش و سود هفتگی (هفته شمسی - شنبه تا جمعه)
        $now = Jalalian::now();
        $dayOfWeek = $now->getDayOfWeek(); // 0 = شنبه, 6 = جمعه
        $startOfWeekJalali = Jalalian::now()->subDays($dayOfWeek);
        $endOfWeekJalali = Jalalian::now()->addDays(6 - $dayOfWeek);
        $startOfWeek = $startOfWeekJalali->toCarbon()->startOfDay();
        $endOfWeek = $endOfWeekJalali->toCarbon()->endOfDay();
        $weekData = $this->getSalesAndProfit($startOfWeek, $endOfWeek);
        $reports['week'] = [
            'total_sales' => $weekData['sales'],
            'total_profit' => $weekData['profit']
        ];

        // 4. مجموع فروش و سود ماه
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();
        $monthData = $this->getSalesAndProfit($startOfMonth, $endOfMonth);
        $reports['month'] = [
            'total_sales' => $monthData['sales'],
            'total_profit' => $monthData['profit']
        ];

        // 5. مجموع فروش و سود ماه قبل
        $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
        $endOfLastMonth = Carbon::now()->subMonth()->endOfMonth();
        $lastMonthData = $this->getSalesAndProfit($startOfLastMonth, $endOfLastMonth);
        $reports['last_month'] = [
            'total_sales' => $lastMonthData['sales'],
            'total_profit' => $lastMonthData['profit']
        ];

        // 6. مجموع فروش و سود سالانه
        $startOfYear = Carbon::now()->startOfYear();
        $endOfYear = Carbon::now()->endOfYear();
        $yearData = $this->getSalesAndProfit($startOfYear, $endOfYear);
        $reports['year'] = [
            'total_sales' => $yearData['sales'],
            'total_profit' => $yearData['profit']
        ];

        return response($reports, 200);
    }

    private function getSalesAndProfit($startDate, $endDate)
    {
        $purchasedProducts = PurchasedProduct::with('product')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $totalSales = 0;
        $totalPurchase = 0;

        foreach ($purchasedProducts as $purchasedProduct) {
            if ($purchasedProduct->product) {
                $totalSales += $purchasedProduct->quantity * $purchasedProduct->product->sale_price;
            }
            $totalPurchase += $purchasedProduct->quantity * $purchasedProduct->purchase_price;
        }

        $totalProfit = $totalSales - $totalPurchase;

        return [
            'sales' => (float) $totalSales,
            'profit' => (float) $totalProfit
        ];
    }
}

