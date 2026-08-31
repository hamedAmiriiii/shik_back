<?php

namespace App\Http\Controllers\Oil;

use App\Http\Controllers\Controller;
use App\Models\Atelier;
use App\Models\Setting;
use App\Models\User;
use App\Support\ProjectType;
use App\Tools\ImageTools;
use App\Tools\PhoneTools;
use App\Tools\SmsTools;
use App\Services\ShopSmsQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OilAuthController extends Controller
{
    private const PLACEHOLDER_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwABmX/9k=';

    private const OTP_PREFIX = 'oil_registration_otp:';

    private const OTP_COOLDOWN_PREFIX = 'oil_registration_otp_sent_at:';

    private const OTP_TTL_MINUTES = 10;

    private const OTP_RESEND_SECONDS = 90;

    /** پیامک آزمایشی برای تعویض روغنی تازه‌ثبت‌شده */
    private const TRIAL_SMS_QUOTA = 50;

    public function sendRegistrationPhoneCode(Request $request)
    {
        $this->assertOilSchema();

        $data = $request->validate([
            'phone' => 'required|numeric|digits:11',
        ]);
        $phone = PhoneTools::normalizeIranPhone($data['phone']) ?? $data['phone'];
        if (! PhoneTools::isValidIranMobile($phone)) {
            return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }

        $cooldownKey = self::OTP_COOLDOWN_PREFIX.$phone;
        $lastSent = Cache::get($cooldownKey);
        if ($lastSent !== null && (time() - (int) $lastSent) < self::OTP_RESEND_SECONDS) {
            $wait = self::OTP_RESEND_SECONDS - (time() - (int) $lastSent);

            return response([
                'message' => 'لطفاً قبل از درخواست مجدد چند لحظه صبر کنید.',
                'retry_after_seconds' => max(1, $wait),
            ], 429);
        }

        $code = (string) mt_rand(10000, 99999);
        Cache::put(self::OTP_PREFIX.$phone, $code, now()->addMinutes(self::OTP_TTL_MINUTES));
        Cache::put($cooldownKey, time(), now()->addMinutes(self::OTP_TTL_MINUTES + 1));

        $smsResult = SmsTools::sendSms($phone, 'کد تأیید ثبت‌نام تعویض روغن: '.$code);

        return response([
            'message' => 'کد تأیید به شمارهٔ شما ارسال شد.',
            'smsResult' => $smsResult,
        ], 201);
    }

    public function register(Request $request)
    {
        $this->assertOilSchema();

        $fields = $request->validate([
            'name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'required|numeric|digits:11',
            'password' => 'required|string|min:6|max:255',
            'shop_name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'verification_code' => 'required|numeric|digits:5',
            'oil_interval_km' => 'nullable|integer|min:1000|max:30000',
        ]);

        $phone = PhoneTools::normalizeIranPhone($fields['phone']) ?? $fields['phone'];
        if (! PhoneTools::isValidIranMobile($phone)) {
            return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }

        $expected = Cache::get(self::OTP_PREFIX.$phone);
        if ($expected === null || (string) $expected !== (string) $fields['verification_code']) {
            return response()->json([
                'message' => $expected === null
                    ? 'کد تأیید منقضی شده است. دوباره درخواست کد بدهید.'
                    : 'کد تأیید اشتباه است.',
            ], 422);
        }

        if (User::where('phone', $phone)->exists()) {
            return response()->json([
                'message' => 'این شماره قبلاً ثبت شده است. وارد شوید.',
            ], 422);
        }

        Cache::forget(self::OTP_PREFIX.$phone);
        Cache::forget(self::OTP_COOLDOWN_PREFIX.$phone);

        $nationalCode = $this->makeUniqueSyntheticNationalCode($phone);
        $placeholder = ImageTools::saveFile(
            $nationalCode.'/personality_image.jpeg',
            base64_decode(self::PLACEHOLDER_JPEG_BASE64, true) ?: ''
        );

        $interval = (int) ($fields['oil_interval_km'] ?? 5000);
        $atelier = Atelier::create(array_merge([
            'name' => $fields['shop_name'],
            'code' => $this->generateUniqueCode($fields['shop_name'], $phone),
            'address' => $fields['address'] ?? '—',
            'business_license' => $placeholder,
            'project_type' => ProjectType::OIL,
            'oil_interval_km' => $interval > 0 ? $interval : 5000,
        ], Atelier::trialAccessAttributes()));

        $user = User::create([
            'name' => $fields['name'],
            'last_name' => $fields['last_name'],
            'phone' => $phone,
            'national_code' => $nationalCode,
            'password' => bcrypt($fields['password']),
            'atelier_id' => $atelier->id,
            'project_type' => ProjectType::OIL,
            'shop_staff_role' => 'owner',
            'gender' => User::USER_GENDER_KEY['مرد'],
            'personality_image' => $placeholder,
            'birth_certificate' => $placeholder,
            'national_cart' => $placeholder,
            'tech_certificate' => $placeholder,
        ]);

        Setting::setShopContext((int) $atelier->id);
        Setting::set('shop_sms_quota', (string) self::TRIAL_SMS_QUOTA);

        $token = $user->createToken('oil-app')->plainTextToken;
        $user->load('atelier');

        return response($this->sessionPayload($user, $token), 201);
    }

    public function login(Request $request)
    {
        $this->assertOilSchema();

        $fields = $request->validate([
            'username' => 'required|string|digits:11',
            'password' => 'required|string',
        ]);

        $phone = PhoneTools::normalizeIranPhone($fields['username']) ?? $fields['username'];
        $user = User::where('phone', $phone)->first();

        if (! $user || ! Hash::check($fields['password'], $user->password)) {
            return response(['message' => 'اطلاعات وارد شده صحیح نیست'], 401);
        }

        if (ProjectType::normalize($user->project_type) !== ProjectType::OIL) {
            return response([
                'message' => 'این حساب مربوط به فروشگاه است، نه تعویض روغن.',
            ], 403);
        }

        $loginError = \App\Services\ShopStaffAccess::assertStaffMayLogin($user);
        if ($loginError !== null) {
            return response(['message' => $loginError, 'error' => $loginError], 403);
        }

        $token = $user->createToken('oil-app')->plainTextToken;
        $user->load('atelier');

        return response($this->sessionPayload($user, $token), 201);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $token = $user->currentAccessToken();
            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }
        }

        return ['message' => 'Logged out'];
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->load('atelier');
        $payload = $this->sessionPayload($user, null);
        unset($payload['token']);

        return response($payload);
    }

    public function updateShop(Request $request)
    {
        $user = $request->user();
        $fields = $request->validate([
            'shop_name' => 'sometimes|required|string|max:255',
            'oil_interval_km' => 'sometimes|required|integer|min:1000|max:30000',
        ]);

        $atelier = $user->atelier;
        if (! $atelier) {
            return response()->json(['message' => 'تعویض روغنی متصل نیست.'], 422);
        }

        $updates = [];
        if (isset($fields['shop_name'])) {
            $updates['name'] = $fields['shop_name'];
        }
        if (isset($fields['oil_interval_km'])) {
            $updates['oil_interval_km'] = (int) $fields['oil_interval_km'];
        }
        if ($updates !== []) {
            $atelier->update($updates);
        }

        $user->load('atelier');
        $payload = $this->sessionPayload($user, null);
        unset($payload['token']);

        return response($payload);
    }

    private function sessionPayload(User $user, ?string $token): array
    {
        $atelier = $user->atelier;
        $payload = [
            'user' => $user,
            'project_type' => ProjectType::OIL,
            'shop' => $atelier ? [
                'id' => (int) $atelier->id,
                'name' => $atelier->name,
                'code' => $atelier->code,
                'oil_interval_km' => $atelier->oilIntervalKm(),
                'project_type' => $atelier->projectType(),
            ] : null,
        ];
        if ($token !== null) {
            $payload['token'] = $token;
        }
        if ($atelier) {
            $payload['shop_access'] = $atelier->accessStatusForApi();
            $payload['sms'] = ShopSmsQuotaService::getSummary((int) $atelier->id);
        }

        return $payload;
    }

    private function assertOilSchema(): void
    {
        if (! Schema::hasColumn('users', 'project_type') || ! Schema::hasTable('oil_visits')) {
            abort(response()->json([
                'message' => 'جداول تعویض روغن هنوز ساخته نشده‌اند. فایل database/sql/add_project_type_and_oil_visits_manual.sql را اجرا کنید.',
            ], 503));
        }
    }

    private function makeUniqueSyntheticNationalCode(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?: '0';
        $base = str_pad(substr($digits, -10), 10, '0', STR_PAD_LEFT);
        for ($i = 0; $i < 500; $i++) {
            $candidate = $base;
            if ($i > 0) {
                $suffix = str_pad((string) (($i % 99) + 1), 2, '0', STR_PAD_LEFT);
                $candidate = substr($base, 0, 8).$suffix;
            }
            if (! User::where('national_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return str_pad((string) (time() % 10000000000), 10, '0', STR_PAD_LEFT);
    }

    private function generateUniqueCode(string $name, string $phone): string
    {
        $slug = Str::slug($name, '-');
        if ($slug === '') {
            $slug = 'oil-'.substr(preg_replace('/\D/', '', $phone) ?: 'shop', -6);
        }
        $slug = substr('oil-'.$slug, 0, 40);
        $candidate = $slug;
        $n = 0;
        while (Atelier::where('code', $candidate)->exists()) {
            $n++;
            $candidate = substr($slug, 0, 32).'-'.$n;
        }

        return $candidate;
    }
}
