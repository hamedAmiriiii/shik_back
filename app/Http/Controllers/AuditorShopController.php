<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ShopAuditorAccess;
use App\Services\ShopStaffAccess;
use Illuminate\Http\Request;

/**
 * سمت حسابرس: لیست فروشگاه‌های متصل و انتخاب فروشگاه فعال.
 */
class AuditorShopController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->requireAuditor($request);

        return response([
            'shops' => ShopAuditorAccess::shopsFor($user),
            'current_atelier_id' => $user->atelier_id ? (int) $user->atelier_id : null,
        ], 200);
    }

    public function select(Request $request, int $atelier)
    {
        $user = $this->requireAuditor($request);

        if (! ShopAuditorAccess::select($user, $atelier)) {
            return response()->json([
                'message' => 'این فروشگاه در لیست فروشگاه‌های شما نیست یا دسترسی شما به آن برداشته شده است.',
            ], 404);
        }

        return response(ShopStaffAccess::sessionPayload($user->fresh()), 200);
    }

    private function requireAuditor(Request $request): User
    {
        $user = $this->requireStaffShopUser($request);
        if (! ShopStaffAccess::isAuditor($user)) {
            abort(response()->json([
                'message' => 'این بخش فقط برای حساب حسابرس است.',
            ], 403));
        }

        return $user;
    }
}
