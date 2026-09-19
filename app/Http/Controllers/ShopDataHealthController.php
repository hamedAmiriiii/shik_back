<?php

namespace App\Http\Controllers;

use App\Services\ShopDataHealthService;
use Illuminate\Http\Request;

class ShopDataHealthController extends Controller
{
    /**
     * GET /api/shop-health
     */
    public function show(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $report = ShopDataHealthService::inspect($atelierId);

        $summary = $report['summary'];
        $headline = 'داده‌های این فروشگاه از نظر این بررسی سالم است.';
        if ((int) $summary['error_count'] > 0) {
            $headline = 'چند مورد باید همین الان بررسی شود.';
        } elseif ((int) $summary['warning_count'] > 0) {
            $headline = 'خطای محاسباتی نیست؛ چند مورد ورود یا عملیات را چک کنید.';
        } elseif ((int) $summary['info_count'] > 0) {
            $headline = 'مورد جدی نیست؛ چند نکته برای دقت بیشتر هست.';
        }

        return response([
            'headline' => $headline,
            'summary' => $summary,
            'findings' => $report['findings'],
        ], 200);
    }
}
