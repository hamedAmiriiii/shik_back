<?php

namespace App\Http\Controllers;

use App\Models\Atelier;
use App\Models\Expense;
use App\Models\Product;
use App\Services\ShopSalesReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $reports = [];

        $todayTehran = Carbon::now()->setTimezone('Asia/Tehran');
        $todayData = ShopSalesReportService::salesAndProfitForDate($atelierId, $todayTehran);
        $reports['today'] = $this->formatPeriodReport($todayData);

        $yesterdayTehran = Carbon::now()->setTimezone('Asia/Tehran')->subDay();
        $yesterdayData = ShopSalesReportService::salesAndProfitForDate($atelierId, $yesterdayTehran);
        $reports['yesterday'] = $this->formatPeriodReport($yesterdayData);

        $carbonNow = Carbon::now()->setTimezone('Asia/Tehran');
        $dayOfWeekCarbon = $carbonNow->dayOfWeek;
        $dayOfWeekJalali = $dayOfWeekCarbon == 6 ? 0 : $dayOfWeekCarbon + 1;

        $nowJalali = Jalalian::fromCarbon($carbonNow);
        $startOfWeekJalali = (clone $nowJalali)->subDays($dayOfWeekJalali);
        $endOfWeekJalali = (clone $startOfWeekJalali)->addDays(6);
        $startOfWeek = $startOfWeekJalali->toCarbon()->startOfDay();
        $endOfWeek = $endOfWeekJalali->toCarbon()->endOfDay();
        $weekData = ShopSalesReportService::salesAndProfitForRange($atelierId, $startOfWeek, $endOfWeek);
        $reports['week'] = $this->formatPeriodReport($weekData);

        $now = Jalalian::now();
        $year = $now->getYear();
        $month = $now->getMonth();
        $startOfMonth = (new Jalalian($year, $month, 1))->toCarbon()->startOfDay();
        $endOfMonth = (new Jalalian($year, $month, 1))->addMonths(1)->subDays(1)->toCarbon()->endOfDay();
        $monthData = ShopSalesReportService::salesAndProfitForRange($atelierId, $startOfMonth, $endOfMonth);
        $reports['month'] = $this->formatPeriodReport($monthData);

        $lastMonthJalali = Jalalian::now()->subMonths(1);
        $lastYear = $lastMonthJalali->getYear();
        $lastMonth = $lastMonthJalali->getMonth();
        $startOfLastMonth = (new Jalalian($lastYear, $lastMonth, 1))->toCarbon()->startOfDay();
        $endOfLastMonth = (new Jalalian($lastYear, $lastMonth, 1))->addMonths(1)->subDays(1)->toCarbon()->endOfDay();
        $lastMonthData = ShopSalesReportService::salesAndProfitForRange($atelierId, $startOfLastMonth, $endOfLastMonth);
        $reports['last_month'] = $this->formatPeriodReport($lastMonthData);

        $yearNow = Jalalian::now()->getYear();
        $startOfYear = (new Jalalian($yearNow, 1, 1))->toCarbon()->startOfDay();
        $endOfYear = (new Jalalian($yearNow, 12, 29))->toCarbon()->endOfDay();
        $yearData = ShopSalesReportService::salesAndProfitForRange($atelierId, $startOfYear, $endOfYear);
        $reports['year'] = $this->formatPeriodReport($yearData);

        $productsInventory = $this->getProductsInventoryValue($atelierId);
        $reports['products_inventory'] = [
            'total_purchase_value' => $productsInventory['total_purchase_value'],
            'total_sale_value' => $productsInventory['total_sale_value'],
        ];

        $reports['expenses'] = $this->getExpensesStatistics($atelierId);

        $reports['meta'] = [
            'atelier_id' => $atelierId,
            'atelier_code' => Atelier::where('id', $atelierId)->value('code'),
            'total_uncollected_installments' => ShopSalesReportService::totalUncollectedInstallments($atelierId),
        ];

        return response($reports, 200);
    }

    /**
     * @param  array<string, float>  $data
     * @return array<string, float>
     */
    private function formatPeriodReport(array $data): array
    {
        return [
            'total_sales' => $data['sales'],
            'total_profit' => $data['profit'],
            'total_returns' => $data['returns'],
            'gross_sales' => $data['gross_sales'],
            'credit_earned_from_purchases' => $data['credit_earned_from_purchases'],
            'manual_credit_granted' => $data['manual_credit_granted'],
            'total_credit_granted' => $data['total_credit_granted'],
            'card_amount' => $data['card_amount'],
            'cash_amount' => $data['cash_amount'],
            'cash_and_card_total' => $data['cash_and_card_total'],
            'installments_collected' => $data['installments_collected'],
            'total_collected' => $data['total_collected'],
            'uncollected_installments' => $data['uncollected_installments'],
            'credit_used_total' => $data['credit_used_total'],
            'settlement_total' => $data['settlement_total'],
        ];
    }

    private function getProductsInventoryValue(int $atelierId)
    {
        $products = Product::where('atelier_id', $atelierId)
            ->select(
                DB::raw('SUM(purchase_price * quantity) as total_purchase_value'),
                DB::raw('SUM(sale_price * quantity) as total_sale_value')
            )->first();

        return [
            'total_purchase_value' => (float) ($products->total_purchase_value ?? 0),
            'total_sale_value' => (float) ($products->total_sale_value ?? 0),
        ];
    }

    private function getExpensesStatistics(int $atelierId)
    {
        $expenseQuery = Expense::where('atelier_id', $atelierId);

        $totalExpenses = (clone $expenseQuery)->sum('amount');
        $totalCurrentExpenses = (clone $expenseQuery)->where('type', 'جاری')->sum('amount');
        $totalCapitalExpenses = (clone $expenseQuery)->where('type', 'سرمایه')->sum('amount');

        $expensesByUser = Expense::where('atelier_id', $atelierId)->select(
            'user_name',
            DB::raw('SUM(CASE WHEN type = "جاری" THEN amount ELSE 0 END) as total_current'),
            DB::raw('SUM(CASE WHEN type = "سرمایه" THEN amount ELSE 0 END) as total_capital'),
            DB::raw('SUM(amount) as total')
        )
            ->groupBy('user_name')
            ->orderBy('user_name')
            ->get();

        return [
            'total_expenses' => (float) $totalExpenses,
            'total_current_expenses' => (float) $totalCurrentExpenses,
            'total_capital_expenses' => (float) $totalCapitalExpenses,
            'expenses_by_user' => $expensesByUser,
        ];
    }
}
