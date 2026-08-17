<?php

namespace App\Http\Controllers;

use App\Services\ShopReferralService;
use Illuminate\Http\Request;

class PublicReferralController extends Controller
{
    /**
     * مشاهدهٔ زیرمجموعه‌ها بدون لاگین — با شماره موبایل، کد معرف، یا توکن.
     *
     * GET /api/referrals/{identifier}
     * مثال: /api/referrals/09399166196
     */
    public function show(Request $request, string $identifier)
    {
        return $this->buildDashboardResponse($identifier);
    }

    /** @deprecated استفاده از show — سازگاری با نسخه قبل */
    public function dashboard(Request $request, string $token)
    {
        return $this->buildDashboardResponse($token);
    }

    protected function buildDashboardResponse(string $identifier)
    {
        $referrer = ShopReferralService::findReferrerByPublicIdentifier($identifier);
        if (! $referrer) {
            return response()->json([
                'message' => 'معرف با این شناسه یافت نشد. شماره موبایل یا کد معرف را بررسی کنید.',
            ], 404);
        }

        $referrer = ShopReferralService::ensureReferralIdentity($referrer);

        return response([
            'referrer' => [
                'name' => trim($referrer->name.' '.$referrer->last_name),
                'phone' => $this->maskPhone($referrer->phone),
                'referral_code' => $referrer->referral_code,
            ],
            'register_link' => ShopReferralService::registerLinkFor($referrer),
            'dashboard_link' => ShopReferralService::dashboardLinkFor($referrer),
            'stats' => ShopReferralService::statsFor($referrer),
            'referrals' => array_map(function (array $row) {
                if (isset($row['referred_user']['phone'])) {
                    $row['referred_user']['phone'] = $this->maskPhone($row['referred_user']['phone']);
                }

                return $row;
            }, ShopReferralService::referralsListFor($referrer)),
        ], 200);
    }

    protected function maskPhone(?string $phone): ?string
    {
        if (! is_string($phone) || strlen($phone) < 7) {
            return $phone;
        }

        return substr($phone, 0, 4).'***'.substr($phone, -4);
    }
}
