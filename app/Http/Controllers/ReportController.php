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

        // 1. مجموع فروش و سود روزانه (بر اساس تاریخ در تایم‌زون تهران)
        $todayTehran = Carbon::now()->setTimezone('Asia/Tehran');
        $todayData = $this->getSalesAndProfitForDate($todayTehran);
        $reports['today'] = [
            'total_sales' => $todayData['sales'],
            'total_profit' => $todayData['profit'],
            'total_returns' => $todayData['returns']
        ];

        // 2. مجموع فروش و سود روز قبل (بر اساس تاریخ در تایم‌زون تهران)
        $yesterdayTehran = Carbon::now()->setTimezone('Asia/Tehran')->subDay();
        $yesterdayData = $this->getSalesAndProfitForDate($yesterdayTehran);
        $reports['yesterday'] = [
            'total_sales' => $yesterdayData['sales'],
            'total_profit' => $yesterdayData['profit'],
            'total_returns' => $yesterdayData['returns']
        ];

        // 3. مجموع فروش و سود هفتگی (هفته شمسی - شنبه تا جمعه)
        // استفاده از Carbon برای محاسبه دقیق‌تر
        $carbonNow = Carbon::now()->setTimezone('Asia/Tehran');
        $dayOfWeekCarbon = $carbonNow->dayOfWeek; // 0 = یکشنبه, 1 = دوشنبه, ..., 6 = شنبه
        
        // تبدیل به فرمت شمسی: شنبه = 0, یکشنبه = 1, ..., جمعه = 6
        // در Carbon: یکشنبه=0, دوشنبه=1, ..., شنبه=6
        // در شمسی: شنبه=0, یکشنبه=1, ..., جمعه=6
        // پس: dayOfWeekCarbon == 6 (شنبه) => 0, else => dayOfWeekCarbon + 1
        $dayOfWeekJalali = $dayOfWeekCarbon == 6 ? 0 : $dayOfWeekCarbon + 1;
        
        $nowJalali = Jalalian::fromCarbon($carbonNow);
        $startOfWeekJalali = (clone $nowJalali)->subDays($dayOfWeekJalali);
        $endOfWeekJalali = (clone $startOfWeekJalali)->addDays(6);
        $startOfWeek = $startOfWeekJalali->toCarbon()->startOfDay();
        $endOfWeek = $endOfWeekJalali->toCarbon()->endOfDay();
        $weekData = $this->getSalesAndProfit($startOfWeek, $endOfWeek);
        $reports['week'] = [
            'total_sales' => $weekData['sales'],
            'total_profit' => $weekData['profit'],
            'total_returns' => $weekData['returns']
        ];

        // 4. مجموع فروش و سود ماه (ماه شمسی)
        $now = Jalalian::now();
        $year = $now->getYear();
        $month = $now->getMonth();
        $startOfMonthJalali = new Jalalian($year, $month, 1);
        $startOfMonth = $startOfMonthJalali->toCarbon()->startOfDay();
        // محاسبه آخرین روز ماه شمسی: اضافه کردن یک ماه و کسر یک روز
        $endOfMonthJalali = (new Jalalian($year, $month, 1))->addMonths(1)->subDays(1);
        $endOfMonth = $endOfMonthJalali->toCarbon()->endOfDay();
        $monthData = $this->getSalesAndProfit($startOfMonth, $endOfMonth);
        $reports['month'] = [
            'total_sales' => $monthData['sales'],
            'total_profit' => $monthData['profit'],
            'total_returns' => $monthData['returns']
        ];

        // 5. مجموع فروش و سود ماه قبل (ماه شمسی قبل)
        $lastMonthJalali = Jalalian::now()->subMonths(1);
        $lastYear = $lastMonthJalali->getYear();
        $lastMonth = $lastMonthJalali->getMonth();
        $startOfLastMonthJalali = new Jalalian($lastYear, $lastMonth, 1);
        $startOfLastMonth = $startOfLastMonthJalali->toCarbon()->startOfDay();
        // محاسبه آخرین روز ماه شمسی قبل: اضافه کردن یک ماه و کسر یک روز
        $endOfLastMonthJalali = (new Jalalian($lastYear, $lastMonth, 1))->addMonths(1)->subDays(1);
        $endOfLastMonth = $endOfLastMonthJalali->toCarbon()->endOfDay();
        $lastMonthData = $this->getSalesAndProfit($startOfLastMonth, $endOfLastMonth);
        $reports['last_month'] = [
            'total_sales' => $lastMonthData['sales'],
            'total_profit' => $lastMonthData['profit'],
            'total_returns' => $lastMonthData['returns']
        ];

        // 6. مجموع فروش و سود سالانه (سال شمسی)
        $yearNow = Jalalian::now()->getYear();
        $startOfYear = (new Jalalian($yearNow, 1, 1))->toCarbon()->startOfDay();
        // آخرین روز سال شمسی (29 اسفند)
        $endOfYear = (new Jalalian($yearNow, 12, 29))->toCarbon()->endOfDay();
        $yearData = $this->getSalesAndProfit($startOfYear, $endOfYear);
        $reports['year'] = [
            'total_sales' => $yearData['sales'],
            'total_profit' => $yearData['profit'],
            'total_returns' => $yearData['returns']
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

    /**
     * محاسبه فروش و سود برای یک تاریخ خاص (در تایم‌زون تهران)
     * پارامتر $dateTehran باید یک Carbon instance با تایم‌زون Asia/Tehran باشد
     */
    private function getSalesAndProfitForDate(Carbon $dateTehran)
    {
        $startOfDayTehran = $dateTehran->copy()->setTimezone('Asia/Tehran')->startOfDay();
        $endOfDayTehran = $dateTehran->copy()->setTimezone('Asia/Tehran')->endOfDay();
        $startString = $startOfDayTehran->format('Y-m-d H:i:s');
        $endString = $endOfDayTehran->format('Y-m-d H:i:s');

        $purchases = Purchase::with('purchasedProducts.product')
            ->whereBetween('created_at', [$startString, $endString])
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

        // محاسبه برگشتی‌ها با اطلاعات محصولات
        $returnedProducts = ReturnedProduct::with('product')
            ->whereBetween('created_at', [$startString, $endString])
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

    private function getSalesAndProfit($startDate, $endDate)
    {
        $startString = $startDate->copy()->setTimezone('Asia/Tehran')->format('Y-m-d H:i:s');
        $endString = $endDate->copy()->setTimezone('Asia/Tehran')->format('Y-m-d H:i:s');

        $purchases = Purchase::with('purchasedProducts.product')
            ->whereBetween('created_at', [$startString, $endString])
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

