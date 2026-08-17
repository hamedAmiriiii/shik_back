<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ShopReferralService;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    /**
     * اطلاعات لینک معرفی و آمار زیرمجموعه‌ها (برای کاربر لاگین‌شده).
     */
    public function show(Request $request)
    {
        $user = $this->requireShopOwnerUser($request);
        $user = ShopReferralService::ensureReferralIdentity($user);

        return response([
            'referral_code' => $user->referral_code,
            'register_link' => ShopReferralService::registerLinkFor($user),
            'dashboard_link' => ShopReferralService::dashboardLinkFor($user),
            'dashboard_api_url' => ShopReferralService::apiDashboardUrl($user),
            'stats' => ShopReferralService::statsFor($user),
            'referrals' => ShopReferralService::referralsListFor($user),
        ], 200);
    }

    protected function requireShopOwnerUser(Request $request): User
    {
        $actor = $this->shopRequestActor($request);
        if (! $actor instanceof User) {
            abort(response()->json(['message' => 'لطفاً وارد شوید.'], 401));
        }

        return $actor;
    }
}
