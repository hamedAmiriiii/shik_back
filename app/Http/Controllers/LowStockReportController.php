<?php

namespace App\Http\Controllers;

use App\Services\LowStockReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LowStockReportController extends Controller
{
    public function show(Request $request, LowStockReportService $service)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        if (! Schema::hasTable('low_stock_reports')) {
            return response([
                'has_report' => false,
                'can_calculate' => false,
                'calculated_at' => null,
                'calculated_date' => null,
                'threshold_used_ratio' => LowStockReportService::USED_RATIO_THRESHOLD,
                'items' => [],
                'counts' => [
                    'products' => 0,
                    'raw_materials' => 0,
                    'total' => 0,
                ],
                'message' => 'جدول low_stock_reports وجود ندارد. migration یا SQL را اجرا کنید.',
            ], 200);
        }

        $report = $service->findForAtelier($atelierId);

        return response($service->serialize($report, $service->canCalculate($report)), 200);
    }

    public function calculate(Request $request, LowStockReportService $service)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        if (! Schema::hasTable('low_stock_reports')) {
            return response([
                'message' => 'جدول low_stock_reports وجود ندارد. migration یا SQL را اجرا کنید.',
            ], 503);
        }

        $existing = $service->findForAtelier($atelierId);
        if ($existing && ! $service->canCalculate($existing)) {
            return response([
                'message' => 'محاسبه امروز انجام شده است. فردا دوباره می‌توانید بروزرسانی کنید.',
                'report' => $service->serialize($existing, false),
            ], 422);
        }

        try {
            $result = $service->calculateAndStore($atelierId);
        } catch (RuntimeException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'لیست کالاهای رو به اتمام بروزرسانی شد',
            'report' => $service->serialize($result['report'], false),
        ], 200);
    }
}
