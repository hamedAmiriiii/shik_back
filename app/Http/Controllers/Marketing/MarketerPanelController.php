<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMarketer;
use App\Models\Marketer;
use App\Models\MarketingSetting;
use App\Services\Marketing\MarketingService;
use Illuminate\Http\Request;

class MarketerPanelController extends Controller
{
    public function __construct(protected MarketingService $marketing)
    {
    }

    /**
     * همهٔ اطلاعات پنل بازاریاب: لینک، آمار، زیرمجموعه‌ها، خریدها و تسویه‌ها.
     */
    public function dashboard(Request $request)
    {
        /** @var Marketer $marketer */
        $marketer = $request->attributes->get(AuthenticateMarketer::ATTRIBUTE);

        try {
            $this->marketing->syncCommissions($marketer);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'marketer' => $this->marketing->formatMarketer($marketer, false),
            'summary' => $this->marketing->summaryFor($marketer),
            'referrals' => $this->marketing->referralsFor($marketer, true),
            'payouts' => $this->marketing->payoutsFor($marketer),
            'attribution_days' => MarketingSetting::attributionDays(),
        ]);
    }
}
