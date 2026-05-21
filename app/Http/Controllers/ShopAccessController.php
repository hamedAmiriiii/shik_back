<?php

namespace App\Http\Controllers;

use App\Models\Atelier;
use Illuminate\Http\Request;

class ShopAccessController extends Controller
{
    /**
     * وضعیت اعتبار استفاده فروشگاه (تاریخ پایان و روزهای باقی‌مانده).
     */
    public function show(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $atelier = Atelier::findOrFail($atelierId);

        return response(array_merge([
            'atelier_id' => $atelierId,
            'shop_name' => $atelier->name,
            'trial_months_on_register' => Atelier::TRIAL_MONTHS,
        ], $atelier->accessStatusForApi()), 200);
    }
}
