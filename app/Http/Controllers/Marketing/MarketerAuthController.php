<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMarketer;
use App\Models\Marketer;
use App\Models\MarketerToken;
use App\Services\Marketing\MarketerOtp;
use App\Services\Marketing\MarketingService;
use Illuminate\Http\Request;

class MarketerAuthController extends Controller
{
    public function __construct(
        protected MarketerOtp $otp,
        protected MarketingService $marketing,
    ) {
    }

    public function sendCode(Request $request)
    {
        $phone = MarketingService::normalizePhone($request->input('phone'));
        if ($phone === null) {
            return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }

        $existing = Marketer::query()->where('phone', $phone)->first();
        if ($existing && ! $existing->is_active) {
            return response()->json(['message' => 'حساب بازاریابی شما غیرفعال شده است.'], 403);
        }

        $wait = $this->otp->send($phone);
        if ($wait > 0) {
            return response()->json([
                'message' => 'لطفاً کمی صبر کنید و دوباره تلاش کنید.',
                'retry_after_seconds' => $wait,
            ], 429);
        }

        return response()->json([
            'message' => 'کد ورود به شماره شما پیامک شد.',
            'resend_after_seconds' => MarketerOtp::RESEND_SECONDS,
        ]);
    }

    public function verify(Request $request)
    {
        $phone = MarketingService::normalizePhone($request->input('phone'));
        if ($phone === null) {
            return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }

        $error = $this->otp->verify($phone, (string) $request->input('code', ''));
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        $marketer = $this->marketing->findOrCreateByPhone($phone);
        if (! $marketer->is_active) {
            return response()->json(['message' => 'حساب بازاریابی شما غیرفعال شده است.'], 403);
        }

        $marketer->forceFill(['last_login_at' => now()])->save();
        $token = MarketerToken::issue($marketer);

        return response()->json([
            'token' => $token,
            'marketer' => $this->marketing->formatMarketer($marketer, false),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $marketer = $this->marketer($request);

        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'card_number' => 'nullable|string|max:32',
            'sheba' => 'nullable|string|max:32',
        ]);

        $updates = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            $updates['name'] = $name !== '' ? $name : null;
        }

        if (array_key_exists('card_number', $data)) {
            $card = preg_replace('/\D/', '', MarketingService::toLatinDigits((string) $data['card_number']));
            if ($card !== '' && strlen($card) !== 16) {
                return response()->json(['message' => 'شماره کارت باید ۱۶ رقم باشد.'], 422);
            }
            $updates['card_number'] = $card !== '' ? $card : null;
        }

        if (array_key_exists('sheba', $data)) {
            $sheba = strtoupper(preg_replace('/[\s\-]/', '', MarketingService::toLatinDigits((string) $data['sheba'])));
            if ($sheba !== '') {
                if (! str_starts_with($sheba, 'IR')) {
                    $sheba = 'IR'.$sheba;
                }
                if (! preg_match('/^IR\d{24}$/', $sheba)) {
                    return response()->json(['message' => 'شماره شبا معتبر نیست (IR + ۲۴ رقم).'], 422);
                }
            }
            $updates['sheba'] = $sheba !== '' ? $sheba : null;
        }

        if ($updates !== []) {
            $marketer->update($updates);
        }

        return response()->json([
            'marketer' => $this->marketing->formatMarketer($marketer->fresh(), false),
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->attributes->get(AuthenticateMarketer::ATTRIBUTE.'_token');
        if ($token instanceof MarketerToken) {
            $token->delete();
        }

        return response()->json(['message' => 'خارج شدید.']);
    }

    protected function marketer(Request $request): Marketer
    {
        return $request->attributes->get(AuthenticateMarketer::ATTRIBUTE);
    }
}
