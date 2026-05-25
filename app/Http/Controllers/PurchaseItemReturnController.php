<?php

namespace App\Http\Controllers;

use App\Services\PurchaseItemReturnGridService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;

class PurchaseItemReturnController extends Controller
{
    /**
     * گرید برگشت از فاکتور (DELETE purchased-products/{purchase}/items/{line}).
     * GET /api/purchase-item-returns/grid
     * GET /api/purchase-item-returns/grid?year=1405&month=3
     */
    public function grid(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $now = Jalalian::fromCarbon(Carbon::now('Asia/Tehran'));
        $request->validate([
            'year' => 'sometimes|integer|min:1300|max:1500',
            'month' => 'sometimes|integer|min:1|max:12',
        ]);

        $year = $request->has('year')
            ? (int) $request->input('year')
            : (int) $now->getYear();
        $month = $request->has('month')
            ? (int) $request->input('month')
            : (int) $now->getMonth();

        $data = PurchaseItemReturnGridService::gridForMonth($atelierId, $year, $month);

        return response(array_merge($data, [
            'meta' => [
                'atelier_id' => $atelierId,
                'source' => 'purchased-products/items',
            ],
        ]), 200);
    }
}
