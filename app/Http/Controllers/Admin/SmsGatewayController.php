<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\Sms\MelipayamakClient;
use App\Services\Sms\SmsGatewayConfig;
use App\Tools\SmsTools;
use Illuminate\Http\Request;

class SmsGatewayController extends Controller
{
    public function show(Request $request)
    {
        $this->requirePlatformAdmin($request);

        return response()->json(SmsGatewayConfig::publicSnapshot());
    }

    public function update(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $data = $request->validate([
            'provider' => 'nullable|string|in:shina,melipayamak',
            'melipayamak_username' => 'nullable|string|max:40',
            'melipayamak_api_key' => 'nullable|string|max:120',
            'melipayamak_from' => 'nullable|string|max:40',
            'melipayamak_otp_body_id' => 'nullable|string|max:20',
            'melipayamak_credit_body_id' => 'nullable|string|max:20',
            'melipayamak_low_quota_body_id' => 'nullable|string|max:20',
            'melipayamak_otp_vars_mode' => 'nullable|string|in:code,shop_code',
        ]);

        SmsGatewayConfig::save($data);

        return response()->json([
            'message' => 'تنظیمات درگاه پیامک ذخیره شد.',
            'settings' => SmsGatewayConfig::publicSnapshot(),
        ]);
    }

    public function credit(Request $request)
    {
        $this->requirePlatformAdmin($request);

        if (SmsGatewayConfig::isMelipayamak()) {
            $json = MelipayamakClient::fromConfig()->getCredit();
            $ok = (int) ($json['RetStatus'] ?? 0) === 1;

            return response()->json([
                'provider' => SmsGatewayConfig::PROVIDER_MELIPAYAMAK,
                'ok' => $ok,
                'credit' => $ok ? $json['Value'] : null,
                'raw' => $json,
            ]);
        }

        $json = SmsTools::getBalance();

        return response()->json([
            'provider' => SmsGatewayConfig::PROVIDER_SHINA,
            'ok' => true,
            'credit' => $json,
            'raw' => $json,
        ]);
    }

    protected function requirePlatformAdmin(Request $request): User
    {
        $actor = $request->user();
        if ($actor instanceof Customer) {
            abort(response()->json(['message' => 'این عملیات فقط برای ادمین است.'], 403));
        }
        if (! $actor instanceof User) {
            abort(response()->json(['message' => 'لطفاً وارد شوید.'], 401));
        }
        if (! $actor->roles()->where('id', User::USER_TYPE_KEY['ادمین'])->exists()) {
            abort(response()->json(['message' => 'فقط ادمین سیستم می‌تواند درگاه پیامک را مدیریت کند.'], 403));
        }

        return $actor;
    }
}
