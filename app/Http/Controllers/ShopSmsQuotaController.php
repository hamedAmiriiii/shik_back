<?php

namespace App\Http\Controllers;

use App\Services\ShopSmsQuotaService;
use Illuminate\Http\Request;

class ShopSmsQuotaController extends Controller
{
    /**
     * موجودی اعتبار پیامک فروشگاه (تعداد پیامک قابل ارسال).
     */
    public function show(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        return response(ShopSmsQuotaService::getSummary($atelierId), 200);
    }

    /**
     * برآورد تعداد پیامک برای یک متن (هر ۷۰ کاراکتر = ۱ پیامک، با احتساب پسوند لغو11).
     */
    public function estimate(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $request->validate([
            'message' => 'required|string|max:2000',
            'receivers_count' => 'nullable|integer|min:1|max:10000',
        ]);

        $estimate = ShopSmsQuotaService::estimate($request->input('message'), $atelierId);
        $receivers = max(1, (int) $request->input('receivers_count', 1));
        $estimate['receivers_count'] = $receivers;
        $estimate['total_sms_parts'] = $estimate['sms_parts'] * $receivers;
        $estimate['can_send_all'] = $estimate['balance'] !== null
            && $estimate['balance'] >= $estimate['total_sms_parts'];

        return response($estimate, 200);
    }
}
