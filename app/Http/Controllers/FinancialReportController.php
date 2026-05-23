<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Services\ShopSalesReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;
use Carbon\Carbon;

class FinancialReportController extends Controller
{
    /**
     * گزارش مالی ماهانه
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function monthlyReport(Request $request)
    {
        try {
            $atelierId = $this->shopAtelierIdOrAbort($request);

            // دریافت محدوده تاریخ (اختیاری)
            $startDate = $request->input('start_date'); // فرمت: YYYY-MM-DD (شمسی)
            $endDate = $request->input('end_date'); // فرمت: YYYY-MM-DD (شمسی)

            // تبدیل تاریخ‌های شمسی به میلادی برای کوئری
            $startCarbon = null;
            $endCarbon = null;

            if ($startDate) {
                try {
                    $startCarbon = Jalalian::fromFormat('Y-m-d', $startDate)->toCarbon();
                } catch (\Exception $e) {
                    return response([
                        'errorText' => 'فرمت تاریخ شروع نامعتبر است. فرمت صحیح: YYYY-MM-DD (شمسی)',
                        'hasError' => true,
                        'statusCode' => 400
                    ], 400);
                }
            }

            if ($endDate) {
                try {
                    $endCarbon = Jalalian::fromFormat('Y-m-d', $endDate)->toCarbon();
                } catch (\Exception $e) {
                    return response([
                        'errorText' => 'فرمت تاریخ پایان نامعتبر است. فرمت صحیح: YYYY-MM-DD (شمسی)',
                        'hasError' => true,
                        'statusCode' => 400
                    ], 400);
                }
            }

            // اگر تاریخ شروع مشخص نشده، اولین تاریخی که داده داریم را پیدا می‌کنیم
            if (!$startCarbon) {
                $firstPurchaseDate = Purchase::forAtelier($atelierId)->min('created_at');
                $firstExpenseDate = Expense::where('atelier_id', $atelierId)->min('date');
                $firstInvoiceDate = Invoice::where('atelier_id', $atelierId)->min('date');
                
                // تبدیل تاریخ‌های expense و invoice به Carbon (اگر وجود داشته باشند)
                $dates = [];
                if ($firstPurchaseDate) {
                    $dates[] = Carbon::parse($firstPurchaseDate);
                }
                if ($firstExpenseDate) {
                    $dates[] = Carbon::parse($firstExpenseDate);
                }
                if ($firstInvoiceDate) {
                    $dates[] = Carbon::parse($firstInvoiceDate);
                }
                
                // اگر داده‌ای وجود داشت، از اولین تاریخ شروع می‌کنیم
                if (!empty($dates)) {
                    $startCarbon = min($dates);
                } else {
                    // اگر هیچ داده‌ای وجود نداشت، از ابتدای سال 1400 شمسی شروع می‌کنیم
                    try {
                        $startCarbon = (new Jalalian(1400, 1, 1, 0, 0, 0))->toCarbon();
                    } catch (\Exception $e) {
                        // اگر خطا در تبدیل تاریخ رخ داد، از ابتدای سال جاری میلادی شروع کن
                        $startCarbon = Carbon::now()->startOfYear();
                    }
                }
            }

            // اگر تاریخ پایان مشخص نشده، تا الان
            if (!$endCarbon) {
                $endCarbon = Carbon::now();
            }

            // تبدیل به ابتدا و انتهای روز
            $startCarbon = $startCarbon->startOfDay();
            $endCarbon = $endCarbon->endOfDay();

        // دریافت تمام ماه‌های موجود در بازه زمانی
        $months = $this->getMonthsInRange($startCarbon, $endCarbon);

        $monthlyData = [];
        $totals = [
            'total_sales' => 0,
            'total_purchases' => 0,
            'total_profit' => 0,
            'total_expenses' => 0,
            'total_invoices' => 0,
            'total_net_profit' => 0,
            'total_account_balance' => 0,
            'total_credit_granted' => 0,
            'card_amount' => 0,
            'cash_amount' => 0,
            'cash_and_card_total' => 0,
            'installments_collected' => 0,
            'total_collected' => 0,
            'uncollected_installments' => 0,
            'credit_used_total' => 0,
        ];

        foreach ($months as $month) {
            $monthData = $this->calculateMonthData(
                $month['start'],
                $month['end'],
                $month['year'],
                $month['month'],
                $atelierId
            );
            
            $monthlyData[] = $monthData;

            // اضافه کردن به مجموع
            $totals['total_sales'] += $monthData['total_sales'];
            $totals['total_purchases'] += $monthData['total_purchases'];
            $totals['total_profit'] += $monthData['total_profit'];
            $totals['total_expenses'] += $monthData['total_expenses'];
            $totals['total_invoices'] += $monthData['total_invoices'];
            $totals['total_net_profit'] += $monthData['net_profit'];
            $totals['total_account_balance'] += $monthData['account_balance'];
            $totals['total_credit_granted'] += $monthData['total_credit_granted'];
            $totals['card_amount'] += $monthData['card_amount'];
            $totals['cash_amount'] += $monthData['cash_amount'];
            $totals['cash_and_card_total'] += $monthData['cash_and_card_total'];
            $totals['installments_collected'] += $monthData['installments_collected'];
            $totals['total_collected'] += $monthData['total_collected'];
            $totals['uncollected_installments'] += $monthData['uncollected_installments'];
            $totals['credit_used_total'] += $monthData['credit_used_total'];
        }

            return response([
                'meta' => [
                    'atelier_id' => $atelierId,
                    'total_uncollected_installments' => ShopSalesReportService::totalUncollectedInstallments($atelierId),
                ],
                'data' => $monthlyData,
                'totals' => $totals,
            ], 200);
        } catch (\Exception $e) {
            return response([
                'errorText' => $e->getMessage(),
                'hasError' => true,
                'statusCode' => 500,
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * دریافت لیست ماه‌ها در بازه زمانی
     */
    private function getMonthsInRange(Carbon $start, Carbon $end): array
    {
        $months = [];
        
        // بررسی معتبر بودن تاریخ شروع و پایان
        $minCarbon = Carbon::create(1621, 1, 1); // سال 1000 شمسی
        $maxCarbon = Carbon::create(2221, 12, 31); // سال 3000 شمسی
        
        // اگر تاریخ شروع خیلی قدیمی است، از ابتدای سال 1400 شمسی شروع کن
        if ($start->lt($minCarbon)) {
            try {
                $start = (new Jalalian(1400, 1, 1, 0, 0, 0))->toCarbon();
            } catch (\Exception $e) {
                $start = Carbon::now()->startOfYear();
            }
        }
        
        // اگر تاریخ پایان خیلی جدید است، تا الان محدود کن
        if ($end->gt($maxCarbon)) {
            $end = Carbon::now();
        }
        
        // اگر تاریخ شروع بعد از تاریخ پایان است، آرایه خالی برگردان
        if ($start->gt($end)) {
            return [];
        }
        
        $current = $start->copy();
        $processedMonths = []; // برای جلوگیری از تکرار
        $maxIterations = 500; // جلوگیری از حلقه بی‌نهایت
        $iteration = 0;

        while ($current->lte($end) && $iteration < $maxIterations) {
            $iteration++;
            
            // تبدیل به شمسی با بررسی معتبر بودن
            try {
                // بررسی اینکه تاریخ معتبر است یا نه (باید بعد از سال 1000 شمسی باشد)
                if ($current->lt($minCarbon)) {
                    // اگر تاریخ خیلی قدیمی است، به ماه بعد برو
                    $current = $current->copy()->addMonth()->startOfMonth();
                    continue;
                }
                
                $jalali = Jalalian::fromCarbon($current);
                $year = $jalali->getYear();
                $month = $jalali->getMonth();
                
                // بررسی معتبر بودن سال (باید بین 1000 تا 3000 باشد)
                if ($year < 1000 || $year > 3000) {
                    // اگر سال معتبر نیست، به ماه بعد برو
                    $current = $current->copy()->addMonth()->startOfMonth();
                    continue;
                }
            } catch (\Exception $e) {
                // اگر خطا در تبدیل تاریخ رخ داد، به ماه بعد برو
                $current = $current->copy()->addMonth()->startOfMonth();
                continue;
            }

            // بررسی اینکه این ماه قبلاً پردازش شده یا نه
            $monthKey = $year . '-' . $month;
            if (isset($processedMonths[$monthKey])) {
                // رفتن به ماه بعد
                $nextMonth = $month + 1;
                $nextYear = $year;
                if ($nextMonth > 12) {
                    $nextMonth = 1;
                    $nextYear++;
                }
                try {
                    $current = (new Jalalian($nextYear, $nextMonth, 1, 0, 0, 0))->toCarbon();
                } catch (\Exception $e) {
                    break; // اگر خطا در تبدیل تاریخ رخ داد، حلقه را متوقف کن
                }
                continue;
            }

            $processedMonths[$monthKey] = true;

            // ابتدا و انتهای ماه شمسی
            try {
                $monthStartJalali = new Jalalian($year, $month, 1, 0, 0, 0);
                $monthStart = $monthStartJalali->toCarbon();
                
                // تعداد روزهای ماه شمسی
                $daysInMonth = $monthStartJalali->getMonthDays();
                $monthEndJalali = new Jalalian($year, $month, $daysInMonth, 23, 59, 59);
                $monthEnd = $monthEndJalali->toCarbon();
            } catch (\Exception $e) {
                // اگر خطا در تبدیل تاریخ رخ داد، از تاریخ فعلی استفاده کن
                $monthStart = $current->copy()->startOfMonth();
                $monthEnd = $current->copy()->endOfMonth();
            }

            // محدود کردن به بازه درخواستی
            if ($monthStart->lt($start)) {
                $monthStart = $start->copy();
            }
            if ($monthEnd->gt($end)) {
                $monthEnd = $end->copy();
            }

            $months[] = [
                'year' => $year,
                'month' => $month,
                'start' => $monthStart,
                'end' => $monthEnd,
                'month_name' => $this->getMonthName($month),
            ];

            // رفتن به ماه بعد (اول ماه بعد)
            $nextMonth = $month + 1;
            $nextYear = $year;
            if ($nextMonth > 12) {
                $nextMonth = 1;
                $nextYear++;
            }
            try {
                $current = (new Jalalian($nextYear, $nextMonth, 1, 0, 0, 0))->toCarbon();
            } catch (\Exception $e) {
                break; // اگر خطا در تبدیل تاریخ رخ داد، حلقه را متوقف کن
            }
        }

        return $months;
    }

    /**
     * محاسبه داده‌های یک ماه
     */
    private function calculateMonthData(Carbon $start, Carbon $end, int $year, int $month, int $atelierId): array
    {
        $metrics = ShopSalesReportService::salesAndProfitForRange($atelierId, $start, $end);
        $netSales = $metrics['sales'];
        $netPurchase = $metrics['net_purchase'];
        $totalProfit = $metrics['profit'];
        $totalCreditGranted = $metrics['total_credit_granted'];

        // کل هزینه‌های جاری: مجموع amount از expenses که type = 'جاری'
        // توجه: date در expenses به صورت DATE ذخیره می‌شود (میلادی)
        $totalExpenses = Expense::where('atelier_id', $atelierId)
            ->where('type', 'جاری')
            ->whereDate('date', '>=', $start->format('Y-m-d'))
            ->whereDate('date', '<=', $end->format('Y-m-d'))
            ->sum('amount');

        $totalInvoices = Invoice::where('atelier_id', $atelierId)
            ->whereDate('date', '>=', $start->format('Y-m-d'))
            ->whereDate('date', '<=', $end->format('Y-m-d'))
            ->sum('amount');

        // خالص سود = سود - هزینه‌های جاری
        $netProfit = $totalProfit - $totalExpenses;

        // موجودی حساب ≈ فروش خالص منهای هزینه‌ها، فاکتورها و اعتبار مصرف‌شده
        $accountBalance = $netSales - $totalExpenses - $totalInvoices - $metrics['credit_used_total'];

        return [
            'year' => $year,
            'month' => $month,
            'month_name' => $this->getMonthName($month),
            'total_sales' => round($netSales, 2),
            'total_purchases' => round($netPurchase, 2),
            'total_profit' => round($totalProfit, 2),
            'total_expenses' => round($totalExpenses, 2),
            'total_invoices' => round($totalInvoices, 2),
            'net_profit' => round($netProfit, 2),
            'account_balance' => round($accountBalance, 2),
            'credit_earned_from_purchases' => round($metrics['credit_earned_from_purchases'], 2),
            'manual_credit_granted' => round($metrics['manual_credit_granted'], 2),
            'total_credit_granted' => round($totalCreditGranted, 2),
            'card_amount' => round($metrics['card_amount'], 2),
            'cash_amount' => round($metrics['cash_amount'], 2),
            'cash_and_card_total' => round($metrics['cash_and_card_total'], 2),
            'installments_collected' => round($metrics['installments_collected'], 2),
            'total_collected' => round($metrics['total_collected'], 2),
            'uncollected_installments' => round($metrics['uncollected_installments'], 2),
            'credit_used_total' => round($metrics['credit_used_total'], 2),
        ];
    }

    /**
     * نام ماه شمسی
     */
    private function getMonthName(int $month): string
    {
        $months = [
            1 => 'فروردین',
            2 => 'اردیبهشت',
            3 => 'خرداد',
            4 => 'تیر',
            5 => 'مرداد',
            6 => 'شهریور',
            7 => 'مهر',
            8 => 'آبان',
            9 => 'آذر',
            10 => 'دی',
            11 => 'بهمن',
            12 => 'اسفند',
        ];

        return $months[$month] ?? '';
    }
}

