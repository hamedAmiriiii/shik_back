<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchasedProduct;
use App\Models\Product;
use App\Models\Expense;
use App\Models\ReturnedProduct;
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

        // 7. مجموع قیمت خرید و فروش کل کالاها (با توجه به موجودی)
        $productsInventory = $this->getProductsInventoryValue();
        $reports['products_inventory'] = [
            'total_purchase_value' => $productsInventory['total_purchase_value'],
            'total_sale_value' => $productsInventory['total_sale_value']
        ];

        // 8. آمار هزینه‌ها
        $expensesStats = $this->getExpensesStatistics();
        $reports['expenses'] = $expensesStats;

        return response($reports, 200);
    }

    private function getSalesAndProfit($startDate, $endDate)
    {
        // دریافت تمام سبدهای خرید در بازه زمانی مشخص
        $purchases = Purchase::with('purchasedProducts.product')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $totalSales = 0;
        $totalPurchase = 0;
        $totalCreditEarned = 0;

        foreach ($purchases as $purchase) {
            // محاسبه فروش واقعی بر اساس sale_price ذخیره شده در purchased_products
            // (که شامل تخفیف‌ها هم می‌شود)
            foreach ($purchase->purchasedProducts as $purchasedProduct) {
                // اگر sale_price ذخیره شده باشد از آن استفاده کن، در غیر این صورت از product.sale_price
                $salePrice = $purchasedProduct->sale_price ?? $purchasedProduct->product->sale_price;
                $totalSales += $purchasedProduct->quantity * $salePrice;
            }

            // محاسبه هزینه خرید محصولات
            foreach ($purchase->purchasedProducts as $purchasedProduct) {
                $totalPurchase += $purchasedProduct->quantity * $purchasedProduct->purchase_price;
            }

            // جمع کردن اعتبار هدیه داده شده (که باید از سود کسر شود)
            $totalCreditEarned += $purchase->credit_earned;
        }
        
        // کسر اعتبار استفاده شده از فروش (چون total_amount آن را کسر کرده بود)
        // اما ما sale_price را استفاده کرده‌ایم، پس باید credit_used را کسر کنیم
        $totalCreditUsed = Purchase::whereBetween('created_at', [$startDate, $endDate])
            ->sum('credit_used');
        $totalSales = $totalSales - $totalCreditUsed;

        // محاسبه برگشتی‌ها با اطلاعات محصولات
        $returnedProducts = ReturnedProduct::with('product')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $totalReturns = 0; // مجموع قیمت فروش برگشتی‌ها
        $totalReturnsPurchase = 0; // مجموع قیمت خرید برگشتی‌ها
        $totalReturnsProfit = 0; // مجموع سود برگشتی‌ها

        foreach ($returnedProducts as $returned) {
            $salePrice = $returned->sale_price;
            $purchasePrice = $returned->product->purchase_price;
            $profit = $salePrice - $purchasePrice;

            $totalReturns += $salePrice;
            $totalReturnsPurchase += $purchasePrice;
            $totalReturnsProfit += $profit;
        }

        // فروش خالص = فروش - برگشتی‌ها
        $netSales = $totalSales - $totalReturns;

        // هزینه خرید خالص = هزینه خرید - هزینه خرید کالاهای برگشتی
        // (فقط کالاهایی که واقعاً فروخته و برگشت نشده‌اند)
        $netPurchase = $totalPurchase - $totalReturnsPurchase;

        // سود = فروش خالص - هزینه خرید خالص - اعتبار هدیه داده شده
        // چون هزینه خرید کالاهای برگشتی را از totalPurchase کم کرده‌ایم،
        // دیگر نیاز به کسر سود برگشتی نیست
        $totalProfit = $netSales - $netPurchase - $totalCreditEarned;

        return [
            'sales' => (float) $netSales, // فروش خالص (منهای برگشتی‌ها)
            'profit' => (float) $totalProfit,
            'returns' => (float) $totalReturns,
            'gross_sales' => (float) $totalSales // فروش خام (قبل از کسر برگشتی‌ها)
        ];
    }

    /**
     * محاسبه مجموع قیمت خرید و فروش کل کالاها با توجه به موجودی
     */
    private function getProductsInventoryValue()
    {
        $products = Product::select(
            DB::raw('SUM(purchase_price * quantity) as total_purchase_value'),
            DB::raw('SUM(sale_price * quantity) as total_sale_value')
        )->first();

        return [
            'total_purchase_value' => (float) ($products->total_purchase_value ?? 0),
            'total_sale_value' => (float) ($products->total_sale_value ?? 0)
        ];
    }

    /**
     * محاسبه آمار هزینه‌ها
     */
    private function getExpensesStatistics()
    {
        // کل هزینه‌ها (مجموع همه)
        $totalExpenses = Expense::sum('amount');

        // کل هزینه‌های جاری
        $totalCurrentExpenses = Expense::where('type', 'جاری')->sum('amount');

        // کل هزینه‌های سرمایه
        $totalCapitalExpenses = Expense::where('type', 'سرمایه')->sum('amount');

        // تفکیک هزینه‌ها بر اساس user_name (جاری و سرمایه)
        $expensesByUser = Expense::select(
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
            'expenses_by_user' => $expensesByUser
        ];
    }
}

