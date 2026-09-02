<?php

namespace App\Http\Controllers\Oil;

use App\Http\Controllers\Controller;
use App\Services\ShopSalesReportService;
use App\Support\ProjectType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;

class OilReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user || ProjectType::normalize($user->project_type) !== ProjectType::OIL) {
            abort(response()->json(['message' => 'دسترسی ندارید.'], 403));
        }
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $today = Carbon::now()->setTimezone('Asia/Tehran');
        $yesterday = $today->copy()->subDay();

        $dayOfWeekCarbon = $today->dayOfWeek;
        $dayOfWeekJalali = $dayOfWeekCarbon == 6 ? 0 : $dayOfWeekCarbon + 1;
        $nowJalali = Jalalian::fromCarbon($today);
        $startOfWeekJalali = (clone $nowJalali)->subDays($dayOfWeekJalali);
        $endOfWeekJalali = (clone $startOfWeekJalali)->addDays(6);

        $year = $nowJalali->getYear();
        $month = $nowJalali->getMonth();
        $startOfMonth = (new Jalalian($year, $month, 1))->toCarbon()->startOfDay();
        $endOfMonth = (new Jalalian($year, $month, 1))->addMonths(1)->subDays(1)->toCarbon()->endOfDay();

        $lastMonthJalali = Jalalian::now()->subMonths(1);
        $startOfLastMonth = (new Jalalian($lastMonthJalali->getYear(), $lastMonthJalali->getMonth(), 1))->toCarbon()->startOfDay();
        $endOfLastMonth = (new Jalalian($lastMonthJalali->getYear(), $lastMonthJalali->getMonth(), 1))->addMonths(1)->subDays(1)->toCarbon()->endOfDay();

        $startOfYear = (new Jalalian($year, 1, 1))->toCarbon()->startOfDay();
        $endOfYear = (new Jalalian($year, 12, 29))->toCarbon()->endOfDay();

        return response()->json([
            'today' => $this->period($atelierId, $today, $today),
            'yesterday' => $this->period($atelierId, $yesterday, $yesterday),
            'week' => $this->period(
                $atelierId,
                $startOfWeekJalali->toCarbon()->startOfDay(),
                $endOfWeekJalali->toCarbon()->endOfDay()
            ),
            'month' => $this->period($atelierId, $startOfMonth, $endOfMonth),
            'last_month' => $this->period($atelierId, $startOfLastMonth, $endOfLastMonth),
            'year' => $this->period($atelierId, $startOfYear, $endOfYear),
        ]);
    }

    /**
     * @return array{sales: float, cost: float, profit: float}
     */
    private function period(int $atelierId, Carbon $start, Carbon $end): array
    {
        $data = $start->toDateString() === $end->toDateString()
            ? ShopSalesReportService::salesAndProfitForDate($atelierId, $start)
            : ShopSalesReportService::salesAndProfitForRange($atelierId, $start, $end);

        return [
            'sales' => (float) $data['sales'],
            'cost' => (float) $data['net_purchase'],
            'profit' => (float) $data['profit'],
        ];
    }
}
