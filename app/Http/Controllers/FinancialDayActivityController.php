<?php

namespace App\Http\Controllers;

use App\Services\FinancialDayActivityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialDayActivityController extends Controller
{
    /**
     * اتفاقات مالی روز / هفته / ماه
     * GET /api/financial-day-activity?filter=today|week|month
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $fields = $request->validate([
            'filter' => ['sometimes', Rule::in(['today', 'week', 'month'])],
        ]);

        $filter = $fields['filter'] ?? 'today';
        $report = FinancialDayActivityService::report($atelierId, $filter);

        return response($report, 200);
    }
}
