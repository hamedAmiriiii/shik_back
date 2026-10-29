<?php

namespace App\Http\Controllers\Billiard;

use App\Http\Controllers\Controller;
use App\Models\BilliardUser;
use App\Services\Billiard\BilliardOtp;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BilliardAuthController extends Controller
{
    public function __construct(protected BilliardOtp $otp)
    {
    }

    public function config()
    {
        $this->assertSchema();

        return response([
            'brand_name' => (string) config('billiard.brand_name'),
            'frontend_url' => (string) config('billiard.frontend_url'),
        ]);
    }

    public function sendCode(Request $request)
    {
        $this->assertSchema();
        $phone = $this->validatedPhone($request);

        $existing = BilliardUser::query()->where('phone', $phone)->first();
        if ($existing && ! $existing->is_active) {
            return response()->json(['message' => 'حساب شما غیرفعال است.'], 403);
        }

        $wait = $this->otp->send($phone);
        if ($wait > 0) {
            return response([
                'message' => 'لطفاً قبل از درخواست مجدد چند لحظه صبر کنید.',
                'retry_after_seconds' => $wait,
            ], 429);
        }

        return response([
            'message' => 'کد ورود به شمارهٔ شما ارسال شد.',
            'is_new' => $existing === null && ! $this->isAdminPhone($phone),
            'retry_after_seconds' => BilliardOtp::RESEND_SECONDS,
        ], 201);
    }

    public function verify(Request $request)
    {
        $this->assertSchema();
        $data = $request->validate([
            'code' => 'required|string|max:10',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
        ]);
        $phone = $this->validatedPhone($request);

        $error = $this->otp->verify($phone, $data['code']);
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        $user = BilliardUser::query()->where('phone', $phone)->first();
        if ($user && ! $user->is_active) {
            return response()->json(['message' => 'حساب شما غیرفعال است.'], 403);
        }

        if ($this->isAdminPhone($phone)) {
            if (! $user) {
                $user = BilliardUser::create([
                    'role' => BilliardUser::ROLE_ADMIN,
                    'phone' => $phone,
                    'first_name' => $data['first_name'] ?? 'مدیر',
                    'membership_status' => 'active',
                    'membership_at' => now(),
                ]);
            } elseif (! $user->isAdmin()) {
                $user->update(['role' => BilliardUser::ROLE_ADMIN]);
            }
        } elseif (! $user) {
            $user = BilliardUser::create([
                'role' => BilliardUser::ROLE_PLAYER,
                'phone' => $phone,
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'membership_status' => 'active',
                'membership_at' => now(),
            ]);
        } else {
            $updates = [];
            if (! $user->first_name && ! empty($data['first_name'])) {
                $updates['first_name'] = $data['first_name'];
            }
            if (! $user->last_name && ! empty($data['last_name'])) {
                $updates['last_name'] = $data['last_name'];
            }
            if ($updates) {
                $user->update($updates);
            }
        }

        return $this->issueToken($user);
    }

    public function me(Request $request)
    {
        /** @var BilliardUser $user */
        $user = $request->user();

        return response(['user' => $user->toSessionArray()]);
    }

    public function updateProfile(Request $request)
    {
        /** @var BilliardUser $user */
        $user = $request->user();
        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'national_id' => 'nullable|string|max:20',
            'birth_date' => 'nullable|date',
            'city' => 'nullable|string|max:100',
            'club' => 'nullable|string|max:150',
            'photo_base64' => 'nullable|string',
        ]);

        if (array_key_exists('national_id', $data)) {
            $nid = preg_replace('/\D/', '', (string) ($data['national_id'] ?? '')) ?: null;
            if ($nid !== null) {
                $exists = BilliardUser::query()
                    ->where('national_id', $nid)
                    ->where('id', '!=', $user->id)
                    ->exists();
                if ($exists) {
                    return response()->json(['message' => 'این کد ملی قبلاً ثبت شده است.'], 422);
                }
            }
            $data['national_id'] = $nid;
        }

        unset($data['photo_base64']);
        $user->fill($data)->save();

        if ($request->filled('photo_base64')) {
            try {
                $user->storePhoto($request->input('photo_base64'));
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response(['user' => $user->fresh()->toSessionArray()]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response(['message' => 'خروج انجام شد.']);
    }

    private function issueToken(BilliardUser $user)
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('billiard')->plainTextToken;

        return response([
            'token' => $token,
            'user' => $user->fresh()->toSessionArray(),
        ]);
    }

    private function validatedPhone(Request $request): string
    {
        $request->validate(['phone' => 'required|string|max:20']);
        $phone = PhoneTools::normalizeIranPhone($request->input('phone'));
        if (! PhoneTools::isValidIranMobile($phone)) {
            abort(response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422));
        }

        return $phone;
    }

    private function isAdminPhone(string $phone): bool
    {
        $list = config('billiard.admin_phones', []);

        return in_array($phone, $list, true);
    }

    private function assertSchema(): void
    {
        if (! Schema::hasTable('billiard_users')) {
            abort(response()->json(['message' => 'جداول سامانهٔ بیلیارد هنوز ساخته نشده‌اند.'], 503));
        }
    }
}
