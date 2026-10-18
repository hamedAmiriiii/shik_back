<?php

namespace App\Http\Controllers;

use App\Services\OutstandingSettlementService;
use Illuminate\Http\Request;

class OutstandingSettlementController extends Controller
{
    /**
     * چک، نسیه و قسط‌های وصول‌نشده — مطالبات (باید بگیریم) و بدهی‌ها (باید بدهیم).
     *
     * GET /api/financial-report/outstanding
     * ?direction=receivable|payable
     * &kind=cheque,credit,installment
     * &due=overdue|upcoming|due_soon
     * &days=7
     * &start_date=1404-01-01&end_date=1404-12-29
     * &search=&phone=&per_page=20&page=1
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $collected = OutstandingSettlementService::collect($atelierId, $request);
        $items = $collected['items'];

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));
        $total = $items->count();
        $data = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return response([
            'data' => $data,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) max(1, ceil($total / max(1, $perPage))),
            'summary' => $collected['summary'],
            'meta' => [
                'atelier_id' => $atelierId,
                'direction' => $request->input('direction', $request->input('flow', 'all')),
                'kind' => $request->input('kind', $request->input('kinds', 'all')),
                'due' => $request->input('due', $request->boolean('overdue') ? 'overdue' : 'all'),
            ],
        ], 200);
    }

    /**
     * یادآوری منوی ادمین: چک‌های ۵ روز آینده + نسیه دریافتی/پرداختنی باز.
     *
     * GET /api/financial-report/reminders?days=5
     */
    public function reminders(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $days = max(1, min(30, (int) $request->input('days', 5)));
        $payload = OutstandingSettlementService::reminders($atelierId, $days);
        $payload['atelier_id'] = $atelierId;

        return response($payload, 200);
    }
}
