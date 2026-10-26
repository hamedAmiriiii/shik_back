<?php

namespace App\Http\Controllers;

use App\Services\FinancialDayActivityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialDayActivityController extends Controller
{
    /**
     * رویدادهای مالی روز / هفته / ماه / بازه دلخواه
     * GET /api/financial-day-activity?filter=today|week|month|custom
     * &from_date=1405-08-03&to_date=1405-08-10  (شمسی Y-m-d)
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $fields = $request->validate([
            'filter' => ['sometimes', Rule::in(['today', 'week', 'month', 'custom'])],
            'from_date' => ['sometimes', 'nullable', 'string', 'max:20'],
            'to_date' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $filter = $fields['filter'] ?? 'today';
        $from = $fields['from_date'] ?? null;
        $to = $fields['to_date'] ?? null;

        if ($filter === 'custom' && ! $from && ! $to) {
            return response()->json([
                'message' => 'برای فیلتر تاریخ دلخواه، از تاریخ یا تا تاریخ را وارد کنید.',
            ], 422);
        }

        $report = FinancialDayActivityService::report($atelierId, $filter, $from, $to);

        return response($report, 200);
    }
}
