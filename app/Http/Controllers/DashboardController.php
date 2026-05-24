<?php

namespace App\Http\Controllers;

use App\Services\ShopDashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * خلاصهٔ صفحهٔ اصلی: تعداد کالا، فروش امروز، کارت، نقد دستی.
     */
    public function summary(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $date = null;
        if ($request->filled('date')) {
            $request->validate(['date' => 'date']);
            $date = Carbon::parse($request->input('date'))->setTimezone('Asia/Tehran');
        }

        $data = ShopDashboardService::summary($atelierId, $date);

        return response(array_merge($data, [
            'meta' => ['atelier_id' => $atelierId],
        ]), 200);
    }

    /**
     * فروش روزانه — پیش‌فرض ۱۰ روز اخیر.
     */
    public function salesByDay(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $days = (int) $request->input('days', 10);
        $request->validate(['days' => 'sometimes|integer|min:1|max:62']);
        if ($request->has('days')) {
            $days = (int) $request->input('days');
        }

        $data = ShopDashboardService::salesByDay($atelierId, $days);

        return response(array_merge($data, [
            'meta' => ['atelier_id' => $atelierId],
        ]), 200);
    }
}
